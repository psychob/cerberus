<?php

    use Docker\Docker;
    use Monolog\Handler\StreamHandler;
    use Monolog\Logger;

    require_once __DIR__ . '/vendor/autoload.php';

    $logger = new Logger('default');
    $logger->pushHandler(new StreamHandler('php://stdout'));

    $logger->debug('ha proxy checker v0');
    $logger->debug('connecting to docker');
    $docker = Docker::create();

    $logger->debug('checking which container enables cerberus');
    $containers = [];
    $loadBalancer = null;

    foreach ($docker->containerList([
        'all' => true,
    ]) as $item) {
        if ($item->getLabels()->offsetExists('cerberus.enable')) {
            if (in_array($item->getLabels()['cerberus.enable'], ['on', 'On', '1', 'true'])) {
                $logger->notice('container found for enabling', [
                    'id' => $item->getId()
                ]);

                if ($item->getState() !== 'exited') {
                    $logger->notice('container is running', [
                        'id' => $item->getId()
                    ]);

                    $containers[] = $item;
                }
            }
        }

        if ($item->getLabels()->offsetExists('cerberus.load_balancer_node')) {
            $logger->notice('container found for load balancing', [
                'id' => $item->getId()
            ]);

            if ($loadBalancer !== null) {
                $logger->error('overriding load balancer', [
                    'old_id' => $loadBalancer->getId(),
                    'new_id' => $item->getId(),
                ]);

                dump($item);

                return 1;
            }

            $loadBalancer = $item;
        }
    }

    if (empty($containers)) {
        $logger->error("No containers found that enable cerberus");
        return 1;
    }

    if (empty($loadBalancer)) {
        $logger->error("Didn't find load balancer");
        return 1;
    }

    $haProxyConfig = '';
    $frontendRules = [];

    foreach ($containers as $container) {
        $networks = $container->getNetworkSettings()->getNetworks();

        if (!($networks instanceof ArrayObject)) {
            $logger->warning("container without network!", [
                'id' => $container->getId()
            ]);

            continue;
        }

        $idContainer = $container->getId() ?? uniqid('', true);
        $ipAddr = $networks['cerberus-network']->getIPAddress();
        if ($container->getLabels()->offsetExists('cerberus.port')) {
            $ipAddr .= ':' . $container->getLabels()->offsetGet('cerberus.port');
        }

        $frontendRules[$container->getLabels()->offsetGet('cerberus.domain')] = "container_${idContainer}_backend";

        $haProxyConfig .= str_replace(
            ['[id]', '[ip_addr_full]'],
            [$idContainer, $ipAddr],
            <<<CONFIG
backend container_[id]_backend
    mode http
    server container_[id]_srv [ip_addr_full]


CONFIG
        );
    }

    $acl = '';
    $use_backend = '';
    foreach ($frontendRules as $domain => $backendId) {
        $dominated = $domain;

        $acl         .= "    acl host_${dominated} hdr(host) -i ${domain}" . PHP_EOL;
        $use_backend .= "    use_backend ${backendId} if host_${dominated}" . PHP_EOL;
    }

    $cfg =<<<HAPROXY_CFG
global
    log stdout format raw local0 
defaults
    log global
    timeout client 30s
    timeout server 30s
    timeout connect 30s

frontend default_frontend
    bind :80
${acl}
${use_backend}
    default_backend internal_cerberus_response
    
${haProxyConfig}

backend internal_cerberus_response
    mode http
    http-request return status 503 content-type "text/plain" string "FUN"


HAPROXY_CFG;

    if (file_get_contents('/opt/haproxy_config/haproxy.cfg') !== $cfg) {
        $logger->notice('new config forced');
        $logger->notice($cfg);

        file_put_contents('/opt/haproxy_config/haproxy.cfg', $cfg);
        $docker->containerRestart($loadBalancer->getId());
    }



