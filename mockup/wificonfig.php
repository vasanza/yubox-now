<?php
define ('MOCKUP_WIFI', '/tmp/wifiscan.json');

Header('Content-Type: application/json');

if (!isset($_SERVER['PATH_INFO'])) {
    Header('HTTP/1.1 400 Bad Request');
    print json_encode(array(
        'success'   =>  FALSE,
        'msg'       =>  'Se requiere indicar ruta'
    ));
    exit();
}

switch ($_SERVER['PATH_INFO']) {
    case '/connection':
        // Manejo según el método HTTP requerido
        $nets = array();
        if (file_exists(MOCKUP_WIFI)) {
            $nets = json_decode(file_get_contents(MOCKUP_WIFI), TRUE);
        }
        switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':     // Información de conexión activa, si existe
            $currnet = NULL;
            foreach ($nets as $net) {
                if ($net['connected']) {
                    $currnet = $net;
                    break;
                }
            }
            if (is_null($currnet)) {
                Header('HTTP/1.1 404 Not Found');
                break;
            }
            $info = array(
                'ssid'      =>  $currnet['ssid'],
                'bssid'     =>  $currnet['bssid'],
                'connected' =>  TRUE,
                'rssi'      =>  $currnet['rssi'],
                // El API de YUBOX no puede reportar aquí esto
                //'authmode'  =>  $currnet['authmode'],
                //'frequency'
                'mac'       =>  NULL,
                'ipv4'      =>  NULL,
                'gateway'   =>  NULL,
                'netmask'   =>  NULL,
                'dns'       =>  array(),
                //'linkspeed'
            );

            // Sólo para mockup. Averiguar cuál interfaz reportar
            $if = 'eth0';
            $output = $retval = NULL;
            exec('/usr/sbin/route -n', $output, $retval);
            if ($retval != 0) {
                Header('HTTP/1.1 500 Internal Server Error');
                print json_encode($output);
                exit();
            }
            foreach ($output as $s) {
                $l = preg_split('/\s+/', $s);
                if (count($l) < 8) continue;
                if ($l[1] == 'Gateway') continue;
                if ($l[1] != '0.0.0.0') {
                    $info['gateway'] = $l[1];
                    $if = $l[7];
                    break;
                }
            }

            foreach (file('/etc/resolv.conf') as $s) {
                $l = preg_split('/\s+/', $s);
                if ($l[0] == 'nameserver') $info['dns'][] = $l[1];
            }

            $output = $retval = NULL;
            exec('/usr/sbin/ifconfig '.$if, $output, $retval);
            if ($retval != 0) {
                Header('HTTP/1.1 500 Internal Server Error');
                print json_encode($output);
                exit();
            }
            $regs = NULL;
            foreach ($output as $s) {
                if (preg_match('/ether (\S+)/', $s, $regs)) {
                    $info['mac'] = $regs[1];
                }
                if (preg_match('/inet (\S+)\s+netmask (\S+)/', $s, $regs)) {
                    $info['ipv4'] = $regs[1];
                    $info['netmask'] = $regs[2];
                }
            }
            print json_encode($info);
            break;
        case 'PUT':     // Conectar suministrando las credenciales
            $hdrs = isset($_SERVER['CONTENT_TYPE']) ? explode('; ', $_SERVER['CONTENT_TYPE']) : array();
            if (count($hdrs) <= 0 || $hdrs[0] != 'application/x-www-form-urlencoded') {
                Header('HTTP/1.1 415 Unsupported Media Type');
                break;
            }
            $data = file_get_contents('php://input');
            $vars = NULL;
            parse_str($data, $vars);
            //print json_encode($vars);
            // ssid authmode psk
            // ssid authmode identity password
            $idx = NULL;
            for ($i = 0; $i < count($nets); $i++) {
                if ($nets[$i]['ssid'] == $vars['ssid']) {
                    $idx = $i;
                    break;
                }
            }
            $badauth = FALSE;
            if (!is_null($idx)) {
                if ($nets[$idx]['authmode'] != $vars['authmode']) {
                    // Modo de autenticación incorrecto
                    $badauth = TRUE;
                } elseif ($nets[$idx]['authmode'] == 5) {
                    $nets[$idx]['identity'] = $vars['identity'];
                    $nets[$idx]['password'] = $vars['password'];
                    if (!($vars['identity'] == 'gatito@gatitas.com' && $vars['password'] == 'michito')) {
                        // Credenciales incorrectas WPA-ENTERPRISE
                        $badauth = TRUE;
                    }
                } elseif ($nets[$idx]['authmode'] > 0) {
                    $nets[$idx]['psk'] = $vars['psk'];
                    if ($vars['psk'] == 'errorerror') {
                        // Esto es un mockup de error inmediato
                        Header('HTTP/1.1 500 Internal Server Error');
                        print json_encode(array(
                            'success'   =>  FALSE,
                            'msg'       =>  'Esto es un error de prueba.'
                        ));
                        exit();
                    }
                    if (!($vars['psk'] == 'gatitolindo')) {
                        // Credenciales incorrectas WEP
                        $badauth = TRUE;
                    }
                }
            }

            if (!is_null($idx)) {
                for ($i = 0; $i < count($nets); $i++) {
                    $nets[$i]['connected'] = FALSE;
                    $nets[$i]['connfail'] = FALSE;
                }
                if ($badauth) {
                    $nets[$idx]['connfail'] = TRUE;
                } else {
                    $nets[$idx]['connected'] = TRUE;
                    $nets[$idx]['saved'] = TRUE;
                }
                $json = json_encode($nets);
                file_put_contents(MOCKUP_WIFI, $json);
            }
            Header('HTTP/1.1 202 Accepted');
            print json_encode(array(
                'success'   =>  TRUE,
                'msg'       =>  'Intentando conexión con credenciales...'
            ));

            break;
        case 'DELETE':  // Olvidar la conexión activa
            foreach ($nets as &$net) {
                if ($net['connected']) {
                    $net['connected'] = FALSE;
                    if ($net['authmode'] == 5) {
                        $net['identity'] = $net['password'] = NULL;
                    } elseif ($net['authmode'] > 0) {
                        $net['psk'] = NULL;
                    }
                }
            }
            $json = json_encode($nets);
            file_put_contents(MOCKUP_WIFI, $json);
            Header('HTTP/1.1 204 No Content');
            break;
        default:
            Header('HTTP/1.1 405 Method Not Allowed');
            Header('Allow: GET, PUT, DELETE');
            print json_encode(array(
                'success'   =>  FALSE,
                'msg'       =>  'Unimplemented request method'
            ));
            exit();
            break;
        }
        break;
    case '/netscan':
        ignore_user_abort(true);
        set_time_limit(0);
        ob_end_clean();

        Header('Content-Type: text/event-stream');
        Header('Cache-Control: no-cache, must-revalidate');
        Header('X-Accel-Buffering: no');

        sse_event(json_encode(array('yubox_control_wifi' => TRUE)), 'WiFiStatus');
        sse_event(_buildAvailableNetworksJSONReport(), 'WiFiScanResult');

        while (connection_status() == CONNECTION_NORMAL) {
            sleep(2);   // Simular retraso en escaneo
            sse_event(_buildAvailableNetworksJSONReport(), 'WiFiScanResult');
        }
        break;
    default:
        Header('HTTP/1.1 404 Not Found');
        print json_encode('Ruta no implementada');
        exit();
}

function _buildAvailableNetworksJSONReport()
{
    $gen = FALSE;
    if (file_exists(MOCKUP_WIFI)) {
        $scan = json_decode(file_get_contents(MOCKUP_WIFI), TRUE);
    } else {
        //$gen = TRUE;
        srand(time());
        $scan = array();
        for ($i = 0; $i < 20; $i++) {
            $mocknet = array(
                'bssid'     =>  sprintf('00:11:00:11:00:%02x', $i),
                'ssid'      =>  sprintf('RED-PRUEBA-%02d', $i),
                'channel'   =>  rand(0, 11),
                'rssi'      =>  rand(-100, 0),
                'authmode'  =>  rand(0, 5),
                'connected' =>  FALSE,
                'connfail'  =>  FALSE,
                'saved'     =>  FALSE,
            );
            if ($mocknet['authmode'] == 5) {
                $mocknet['identity'] = $mocknet['password'] = NULL;
            } elseif ($mocknet['authmode'] > 0) {
                $mocknet['psk'] = NULL;
            }
            $scan[] = $mocknet;
        }
    }
    for ($i = 0; $i < count($scan); $i++) {
        $rssi = $scan[$i]['rssi'] + rand(-5, 5);
        if ($rssi > 0) $rssi = 0;
        if ($rssi < -100) $rssi = -100;
        $scan[$i]['rssi'] = $rssi;
    }
    if ($gen) {
        $max = 0;
        for ($i = 1; $i < count($scan); $i++) {
            if ($scan[$i]['rssi'] > $scan[$max]['rssi']) $max = $i;
        }
        $scan[$max]['connected'] = TRUE;
        //$scan[$max]['connfail'] = TRUE;
    }
    $json = json_encode($scan);
    file_put_contents(MOCKUP_WIFI, $json);

    return $json;
}

function sse_event($data, $event = NULL)
{
    if (!is_null($event)) print "event: {$event}\n";
    print "data: {$data}\n\n";  // TODO: no maneja data con saltos de línea
    flush();
}