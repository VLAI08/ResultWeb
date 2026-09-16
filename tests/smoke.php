<?php
// Prueba de humo del nucleo (sin BD, sin escribir nada):
//   php tests/smoke.php
// Antes → problema: 0 pruebas automatizadas; los cambios de plantilla o barcode
// se verificaban solo a mano. Cambio: verificador ejecutable que falla con exit 1.
require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$failures = 0;

function verify(string $name, bool $cond): void
{
    global $failures;
    echo ($cond ? 'PASS' : 'FALLO') . " {$name}\n";
    if (!$cond) { $failures++; }
}

// 1. Plantilla PDF: render real con un DTO minimo
$loader = new Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
$twig = new Environment($loader, ['cache' => false]);

$fakeSection = [
    'name' => 'AREA DE PRUEBAS SMOKE',
    'validated_by' => 'SMOKEY',
    'signature' => '',
    'exams' => [[
        'exam_name' => 'EJEMPLO AUTOMATIZADO',
        'processing_date' => '2026-01-02', 'processing_time' => '',
        'validation_date' => '2026-01-02', 'validation_time' => '',
        'details' => [
            ['analito' => 'ANALITO PRUEBA......:', 'result' => '1.23', 'units' => 'mg/dL', 'min' => '1', 'max' => '2'],
            ['analito' => 'V.Ref: positivo > 1.5', 'result' => '', 'units' => '', 'min' => '', 'max' => ''],
        ],
    ]],
];
$dto = [
    'client_name' => 'EMPRESA X',
    'request_code' => 'TEST0001',
    'reception_date' => '2026-01-01 08:00:00',
    'identification_type' => 'CC',
    'identification_number' => '123456789',
    'today' => '2026-01-01 09:00:00',
    'patient_name' => 'PRUEBA SMOKE TEST',
    'age' => '30', 'age_unit' => 'anos', 'sex' => 'Femenino',
    'doctors' => 'MEDICOS VARIOS', 'phone' => '3000000000', 'exams' => 'TEST',
    'validated' => true, 'barcode' => 'CCTEST000120260101',
    'header' => '', 'footer' => '', 'prevalidated' => false,
    'descriptive_texts' => [],
    'sections' => [$fakeSection],
];

try {
    $html = $twig->render('pdf/result.html.twig', ['detail' => $dto, 'barcode_img' => '']);
    verify('render plantilla PDF no vacio', $html !== '');
    verify('ficha paciente presente en la guia', str_contains($html, 'Orden Interna'));
    verify('titulo de seccion presente', str_contains($html, 'AREA DE PRUEBAS SMOKE'));
    verify('fila de referencia fusionada', str_contains($html, 'ref-row'));
    verify('logo del PDF en flujo (sin position fixed)', !preg_match('/\.header-logo[^}]*position:\s*fixed/', $html));
} catch (Throwable $e) {
    echo 'FALLO render plantilla: ' . $e->getMessage() . "\n";
    $failures++;
}

// 2. dompdf genera binario valido del HTML
try {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'helvetica');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    $pdf = (string) $dompdf->output();
    verify('PDF con cabecera %PDF-', str_starts_with($pdf, '%PDF-'));
    verify('PDF pesa mas de 2KB', strlen($pdf) > 2000);
} catch (Throwable $e) {
    echo 'FALLO dompdf: ' . $e->getMessage() . "\n";
    $failures++;
}

// 3. Codigo de barras Code128 (tc-lib-barcode)
try {
    $barcode = new Com\Tecnick\Barcode\Barcode();
    $obj = $barcode->getBarcodeObj('C128', 'CC1234567890', 520, 60, 'black', [0, 0, 0, 0]);
    $png = $obj->getPngData();
    verify('barcode C128 genera PNG', strlen($png) > 100 && str_starts_with($png, "\x89PNG"));
} catch (Throwable $e) {
    echo 'FALLO barcode: ' . $e->getMessage() . "\n";
    $failures++;
}

// 4. Configuracion de seguridad y suscriptores
$conf = (string) file_get_contents(__DIR__ . '/../config/packages/security.yaml');
verify('firewalls en security.yaml', str_contains($conf, 'firewalls:'));
$sub = (string) @file_get_contents(__DIR__ . '/../src/EventSubscriber/SecurityHeadersSubscriber.php');
verify('SecurityHeadersSubscriber con X-Frame-Options', $sub !== '' && str_contains($sub, 'X-Frame-Options'));

echo "\n" . ($failures === 0 ? 'SMOKE OK — todo paso' : "SMOKE FALLOS: {$failures}") . "\n";
exit($failures === 0 ? 0 : 1);
