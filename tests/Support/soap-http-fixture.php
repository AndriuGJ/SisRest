<?php

// Isolated loopback HTTP peer. Never persist or output the SOAP envelope, ticket or credentials.
try {
    $config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        throw new RuntimeException('fixture socket');
    }
    $address = stream_socket_get_name($server, false);
    fwrite(STDOUT, json_encode(['address' => $address])."\n");
    fflush(STDOUT);
    $peer = stream_socket_accept($server, 5);
    if ($peer === false) {
        throw new RuntimeException('fixture accept');
    }
    stream_set_timeout($peer, 5);
    $first = fgets($peer, 8192);
    $headers = [];
    $headerBytes = 0;
    while (($line = fgets($peer, 8192)) !== false && trim($line) !== '') {
        $headerBytes += strlen($line);
        if ($headerBytes > 16384 || ! str_contains($line, ':')) {
            throw new RuntimeException('fixture headers');
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))][] = trim($value);
    }
    $length = $headers['content-length'][0] ?? '';
    if (! ctype_digit($length) || (int) $length > 65536) {
        throw new RuntimeException('fixture length');
    }
    $body = '';
    while (strlen($body) < (int) $length) {
        $part = fread($peer, (int) $length - strlen($body));
        if ($part === false || $part === '') {
            throw new RuntimeException('fixture body');
        }
        $body .= $part;
    }
    $xml = new DOMDocument;
    if (! @$xml->loadXML($body, LIBXML_NONET) || $xml->doctype !== null) {
        throw new RuntimeException('fixture XML');
    }
    $xp = new DOMXPath($xml);
    $xp->registerNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
    $xp->registerNamespace('b', 'http://service.sunat.gob.pe');
    $xp->registerNamespace('w', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd');
    $safe = [
        'post_http11' => $first === "POST /billService HTTP/1.1\r\n",
        'content_type' => count($headers['content-type'] ?? []) === 1 && strtolower($headers['content-type'][0]) === 'text/xml; charset=utf-8',
        'soap_action' => ($headers['soapaction'] ?? []) === ['"urn:getStatus"'],
        'host' => ($headers['host'] ?? []) === [$address],
        'connection_close' => array_map('strtolower', $headers['connection'] ?? []) === ['close'],
        'body_length' => count($headers['content-length'] ?? []) === 1 && strlen($body) === (int) $length,
        'uncompressed' => ! isset($headers['content-encoding']) && ! isset($headers['transfer-encoding']),
        'no_http_auth' => ! isset($headers['authorization']),
        'no_expect' => ! isset($headers['expect']),
        'one_operation' => $xp->evaluate('count(/s:Envelope/s:Body/b:getStatus)') === 1.0 && $xp->evaluate('count(/s:Envelope/s:Body/*)') === 1.0,
        'ticket_matches' => $xp->evaluate('string(/s:Envelope/s:Body/b:getStatus/*[local-name()="ticket"])') === $config['ticket'],
        'wsse_username_matches' => $xp->evaluate('string(/s:Envelope/s:Header/w:Security/w:UsernameToken/w:Username)') === $config['username'],
        'wsse_password_matches' => $xp->evaluate('string(/s:Envelope/s:Header/w:Security/w:UsernameToken/w:Password)') === $config['password'],
    ];
    unset($body, $xml, $xp, $headers); // Metadata only is written to stdout.
    if (! ($config['drop_headers'] ?? false)) {
        $status = $config['http_status'] ?? 200;
        $reason = [200 => 'OK', 500 => 'Internal Server Error', 503 => 'Service Unavailable'][$status];
        $payload = $config['response'];
        $responseHeaders = "HTTP/1.1 $status $reason\r\nContent-Type: text/xml; charset=utf-8\r\nConnection: close\r\n";
        if ($config['chunked'] ?? false) {
            $responseHeaders .= "Transfer-Encoding: chunked\r\n\r\n";
            $payload = dechex(strlen($payload))."\r\n".$payload."\r\n0\r\n\r\n";
        } elseif (! ($config['close_delimited'] ?? false)) {
            $responseHeaders .= 'Content-Length: '.strlen($payload)."\r\n\r\n";
        } else {
            $responseHeaders .= "\r\n";
        }
        $out = $responseHeaders.$payload;
        while ($out !== '') {
            $written = fwrite($peer, $out);
            if (! is_int($written) || $written === 0) {
                throw new RuntimeException('fixture write');
            }
            $out = substr($out, $written);
        }
    }
    fclose($peer);
    fclose($server);
    fwrite(STDOUT, json_encode($safe, JSON_THROW_ON_ERROR)."\n");
    exit(0);
} catch (Throwable) {
    // No exception message/trace: it might contain request data.
    fwrite(STDERR, "Local SOAP fixture failed.\n");
    exit(1);
}
