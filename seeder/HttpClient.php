<?php
if (php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit("Acceso denegado.\n");
}


class HttpClient
{
    public function __construct(private Logger $logger)
    {
    }

    /**
     * GET con reintentos y backoff exponencial. Si la API responde 429
     * (rate limit), espera y reintenta en vez de tumbar el proceso.
     *
     * @param array $headers ej. ['X-Auth-Token: xxx'] o ['x-apisports-key: xxx']
     */
    public function get(string $url, array $headers = [], int $maxIntentos = 4): ?array
    {
        $intento = 0;
        $espera = 2; // segundos, se duplica en cada reintento

        // ESPN (site.api.espn.com) esta detras de deteccion de bots que
        // distingue un request de curl de uno de navegador real aunque el
        // User-Agent diga lo mismo -- mira version de HTTP, orden/presencia
        // de headers tipicos de Chrome, y si el cliente acepta compresion.
        // Por eso mandamos el set completo de headers "de navegador" y
        // forzamos HTTP/2, no solo el User-Agent.
        $headersNavegador = array_merge($headers, [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9,es;q=0.8',
            'Referer: https://www.espn.com/',
            'Origin: https://www.espn.com',
            'Sec-Fetch-Site: same-site',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Dest: empty',
        ]);

        // Bundle de certificados raiz (CA). El PHP de MAMP en Windows no trae uno
        // configurado y falla con "SSL certificate problem: unable to get local
        // issuer certificate". Se usa, si existe, CURL_CAINFO del .env o el archivo
        // seeder/cacert.pem. La verificacion SSL queda ACTIVA.
        $caBundle = (class_exists('Env') ? Env::get('CURL_CAINFO') : null) ?: (__DIR__ . '/cacert.pem');
        $opcionesCa = is_file($caBundle) ? [CURLOPT_CAINFO => $caBundle] : [];

        while ($intento < $maxIntentos) {
            $intento++;
            $ch = curl_init($url);
            curl_setopt_array($ch, $opcionesCa + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headersNavegador,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                // Deja que curl mande Accept-Encoding y descomprima solo,
                // como hace un navegador real (curl sin esto no pide gzip).
                CURLOPT_ENCODING       => '',
                // Forzar HTTP/2 -- un curl que solo habla HTTP/1.1 es una
                // de las señales que usan estos sistemas de deteccion.
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // Windows/schannel a veces falla el chequeo de revocacion
                // del certificado (CRYPT_E_NO_REVOCATION_CHECK) aunque el
                // certificado en si sea valido. Esto le dice a curl que
                // saltee SOLO ese chequeo puntual, sin bajar la seguridad
                // general (la verificacion del certificado sigue activa).
                CURLOPT_SSL_OPTIONS    => CURLSSLOPT_NO_REVOKE,
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $this->logger->error("cURL: $curlError ($url)");
                sleep($espera);
                $espera *= 2;
                continue;
            }

            if ($status === 429) {
                $this->logger->info("Rate limit alcanzado, esperando {$espera}s ($url)");
                sleep($espera);
                $espera *= 2;
                continue;
            }

            if ($status >= 500) {
                $this->logger->error("HTTP $status del servidor, reintentando ($url)");
                sleep($espera);
                $espera *= 2;
                continue;
            }

            if ($status >= 400) {
                $this->logger->error("HTTP $status: $url");
                return null;
            }

            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Respuesta no es JSON valido: $url");
                return null;
            }

            // Pausa cortesia entre requests exitosos para no rozar el limite
            usleep(300000); // 0.3s

            return $data;
        }

        $this->logger->error("Se agotaron los reintentos: $url");
        return null;
    }
}