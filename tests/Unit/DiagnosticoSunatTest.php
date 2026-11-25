<?php

use App\Models\ConfiguracionFacturacion;
use App\Services\Facturacion\FalloSunat;
use App\Services\Facturacion\LeerCdr;
use App\Services\Facturacion\TransporteSunat;
use Greenter\Ws\Services\SoapClient;

afterEach(fn () => Mockery::close());

it('classifies SOAP faults without retaining secrets or chaining exceptions', function (string $codigo, string $mensaje, string $tipo, ?string $sunat) {
    $fault = new SoapFault($codigo, $mensaje.' SOL-SECRET PRIVATE-KEY CERTIFICATE', null, ['Password' => 'SOL-SECRET']);
    $soap = Mockery::mock(SoapClient::class);
    $soap->shouldReceive('call')->once()->andThrow($fault);
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldReceive('cliente')->once()->andReturn($soap);
    try {
        $transport->enviar(new ConfiguracionFacturacion, '20131312955', '01', '20131312955-01-F001-1', '<Invoice/>');
        test()->fail('Se esperaba un diagnóstico seguro.');
    } catch (FalloSunat $error) {
        expect($error->tipo)->toBe($tipo)->and($error->codigoSunat)->toBe($sunat)->and($error->getPrevious())->toBeNull()
            ->and(json_encode($error->seguro()))->not->toContain('SOL-SECRET', 'PRIVATE-KEY', 'CERTIFICATE', 'Password');
    }
})->with([
    ['HTTP', 'php_network_getaddresses: getaddrinfo failed', 'conexion_dns', null],
    ['HTTP', 'SSL operation failed: certificate verify failed', 'conexion_tls', null],
    ['HTTP', 'Connection timed out', 'conexion_timeout', null],
    ['HTTP', 'Could not connect to host', 'conexion_red', null],
    ['soap-env:Client.0102', 'Usuario o contraseña incorrectos', 'soap_autenticacion', '0102'],
    ['0104', 'Error', 'soap_autenticacion', '0104'],
    ['wsse:FailedAuthentication', 'Error', 'soap_autenticacion', null],
    ['soap-env:Server.0100', 'Servidor indisponible', 'soap_sunat', '0100'],
    ['soap-env:Client.0402', 'Documento duplicado', 'soap_sunat', '0402'],
    ['soap-env:Server.0835', 'Error del servidor', 'soap_sunat', '0835'],
    ['soap-env:Client', '12345 no debe extraerse del mensaje', 'soap_sunat', null],
]);

it('does not persist arbitrary exception classes messages paths or codes', function () {
    $error = FalloSunat::desde(new RuntimeException('/private/path SOL-SECRET', 12345));
    expect($error->tipo)->toBe('error_no_clasificado')->and($error->codigoSunat)->toBeNull()
        ->and(json_encode($error->seguro()))->not->toContain('SOL-SECRET', '/private/path', '12345');
});

it('diagnoses malformed SOAP response structure', function () {
    $soap = Mockery::mock(SoapClient::class);
    $soap->shouldReceive('call')->once()->andReturn((object) ['unexpected' => 'SECRET']);
    $transport = Mockery::mock(TransporteSunat::class)->makePartial();
    $transport->shouldReceive('cliente')->once()->andReturn($soap);
    expect(fn () => $transport->enviar(new ConfiguracionFacturacion, '20131312955', '01', '20131312955-01-F001-1', '<Invoice/>'))->toThrow(FalloSunat::class, 'estructura esperada');
});

it('diagnoses unsafe or malformed CDR files without exposing their bytes', function (string $contenido, string $tipo) {
    try {
        (new LeerCdr)->leer($contenido, '20131312955-01-F001-1', '20131312955');
        test()->fail('Se esperaba un error de CDR.');
    } catch (FalloSunat $error) {
        expect($error->tipo)->toBe($tipo)->and($error->seguro()['categoria'])->toBe('cdr_local');
    }
})->with([
    ['NOT-A-ZIP SECRET', 'cdr_zip'],
    [str_repeat('x', 2 * 1024 * 1024 + 1), 'cdr_tamano'],
]);

it('keeps strict CDR XML reference code and ZIP entry protections with distinct diagnostics', function (string $caso, string $tipo) {
    $nombre = '20131312955-01-F001-1';
    $xml = '<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cac:ReceiverParty><cac:PartyIdentification><cbc:ID>20131312955</cbc:ID></cac:PartyIdentification></cac:ReceiverParty><cac:DocumentResponse><cac:Response><cbc:ReferenceID>F001-1</cbc:ReferenceID><cbc:ResponseCode>SECRET</cbc:ResponseCode></cac:Response></cac:DocumentResponse></ApplicationResponse>';
    $xml = match ($caso) {
        'xml' => '<broken SECRET',
        'doctype' => '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///private/key">]>'.$xml,
        'referencia' => str_replace('F001-1</cbc:ReferenceID>', 'F001-2</cbc:ReferenceID>', $xml),
        default => $xml,
    };
    $file = tempnam(sys_get_temp_dir(), 'cdr-test');
    try {
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString('R-'.$nombre.'.xml', $xml);
        if ($caso === 'zip_extra') {
            $zip->addFromString('unexpected.xml', '<SECRET/>');
        }
        if ($caso === 'zip_nombre') {
            $zip->renameName('R-'.$nombre.'.xml', 'R-other.xml');
        }
        $zip->close();
        try {
            (new LeerCdr)->leer(file_get_contents($file), $nombre, '20131312955');
            test()->fail('Se esperaba rechazo del CDR inválido.');
        } catch (FalloSunat $error) {
            expect($error->tipo)->toBe($tipo)->and(json_encode($error->seguro()))->not->toContain('SECRET', '/private/key');
        }
    } finally {
        unlink($file);
    }
})->with([
    ['xml', 'cdr_xml'], ['doctype', 'cdr_xml'], ['referencia', 'cdr_referencia'], ['codigo', 'cdr_codigo'], ['zip_extra', 'cdr_nombre'], ['zip_nombre', 'cdr_nombre'],
]);

it('recognizes PHP transport warnings converted to exceptions without logging their contents', function () {
    $error = FalloSunat::desde(new ErrorException('SOAP SSL operation failed: certificate verify failed SECRET'));
    expect($error->tipo)->toBe('conexion_tls')->and($error->getPrevious())->toBeNull()
        ->and(json_encode($error->seguro()))->not->toContain('SECRET');
});
