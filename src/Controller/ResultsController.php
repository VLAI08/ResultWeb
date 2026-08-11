<?php

namespace App\Controller;

use App\Service\FtpService;
use App\Service\PdfService;
use App\Service\ResultsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ResultsController extends AbstractController
{
    public function __construct(
        private ResultsService $results,
        private FtpService $ftpService,
        private PdfService $pdfService,
    ) {
    }

    private function sessionUser(Request $request): ?array
    {
        $user = $request->getSession()->get('user');
        return is_array($user) ? $user : null;
    }

    /**
     * Lista de solicitudes del paciente (mis resultados).
     * GET: identification, type_doc
     */
    #[Route('/resultCrud', name: 'results_crud', methods: ['GET'])]
    public function resultCrud(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (!$user) {
            return $this->json(['message' => 'No autorizado'], 401);
        }
        $identification = (string) $request->query->get('identification', '');
        $typeDoc = (string) $request->query->get('type_doc', '');

        if (!$identification || !$typeDoc) {
            $identification = (string) ($user['identification'] ?? '');
            $typeDoc = (string) ($user['identificationtype'] ?? 'CC');
        }
        if (!$identification || !$typeDoc) {
            return $this->json(['total_count' => 0, 'items' => []]);
        }

        $isPatient = ($user['type'] ?? 'person') === 'person';
        try {
            $rows = $this->results->findByIdentification($identification, $typeDoc, $isPatient);
        } catch (\Throwable $e) {
            return $this->json(['total_count' => 0, 'items' => [], 'error' => 'Error consultando las solicitudes']);
        }
        return $this->json(['total_count' => count($rows), 'items' => $rows]);
    }

    /**
     * Búsqueda de pacientes con filtros (equivalente a /results/labs).
     * GET: identification_number, identification_type, name, last_name, start_date, end_date
     */
    #[Route('/results/labs', name: 'results_labs', methods: ['GET'])]
    public function labsSearch(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (!$user) {
            return $this->json(['message' => 'No autorizado'], 401);
        }
        $isAdmin = ($user['type'] ?? '') === 'admin';
        $clientCodes = $isAdmin ? null : (string) ($user['code'] ?? '');

        // Evita la consulta masiva (25s): se exige al menos un filtro (replica validación de V2026).
        $hasFilter = $request->query->get('identification_number')
            || $request->query->get('identification_type')
            || $request->query->get('name')
            || $request->query->get('last_name')
            || ($request->query->get('start_date') && $request->query->get('end_date'));
        if (!$hasFilter) {
            return $this->json(['total_count' => 0, 'items' => [], 'error' => 'Debe seleccionar por lo menos un filtro']);
        }

        try {
            $rows = $this->results->findByParameter(
                $clientCodes,
                $request->query->get('identification_number'),
                $request->query->get('identification_type'),
                $request->query->get('name'),
                $request->query->get('last_name'),
                $request->query->get('start_date'),
                $request->query->get('end_date'),
            );
        } catch (\Throwable $e) {
            return $this->json(['total_count' => 0, 'items' => [], 'error' => 'Error consultando los resultados']);
        }
        return $this->json(['total_count' => count($rows), 'items' => $rows]);
    }

    /**
     * Detalle de una solicitud (código de orden) con secciones/exámenes.
     */
    #[Route('/result/detail/{requestCode}', name: 'results_detail', methods: ['GET'])]
    public function detail(Request $request, string $requestCode): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (!$user) {
            return $this->json(['message' => 'No autorizado'], 401);
        }
        $isPatient = ($user['type'] ?? 'person') === 'person';
        $prevalidated = $request->query->has('prevalidated')
            ? in_array((string) $request->query->get('prevalidated'), ['1', 'true'], true)
            : null;
        try {
            $detail = $this->results->findByRequest($requestCode, $isPatient, $prevalidated);
        } catch (\Throwable $e) {
            return $this->json(['message' => 'Error consultando el resultado'], 500);
        }
        if (!$detail) {
            return $this->json(['message' => 'No se encontraron resultados para esta solicitud'], 404);
        }
        return $this->json($detail);
    }

    /**
     * Estadísticas del dashboard según el perfil (admin/company/person).
     */
    #[Route('/api/dashboard', name: 'api_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (!$user) {
            return $this->json(['message' => 'No autorizado'], 401);
        }
        $type = (string) ($user['type'] ?? 'person');
        try {
            $stats = $this->results->dashboardStats(
                $type,
                (string) ($user['identification'] ?? ''),
                (string) ($user['identificationtype'] ?? 'CC'),
                (string) ($user['code'] ?? '')
            );
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Error consultando el dashboard'], 500);
        }
        return $this->json($stats);
    }

    /**
     * Valida si la solicitud puede descargar PDF (pago/cobro).
     */
    #[Route('/valid_result', name: 'results_validate', methods: ['GET'])]
    public function validResult(Request $request): JsonResponse
    {
        if (!$this->sessionUser($request)) {
            return $this->json(['message' => 'No autorizado'], 401);
        }
        $solicitud = (string) $request->query->get('_solicitud', '');
        if (!$solicitud) {
            return $this->json(['success' => false, 'state' => -1, 'msg' => 'Solicitud inválida']);
        }
        try {
            $ok = $this->results->isPaid($solicitud);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'state' => -1, 'msg' => 'Error validando pago']);
        }
        if (!$ok) {
            return $this->json(['success' => true, 'state' => 3, 'msg' => 'No puede descargar este resultado, debe estar al día con el pago']);
        }
        return $this->json(['success' => true, 'state' => 1]);
    }

    /**
     * Genera el PDF localmente (sin depender de FTP) o desde FTP si hay URL de archivo.
     * Parámetros opcionales (replica V2026): ?prevalidated=1|0 y ?url=<examenes>|no
     */
    #[Route('/result/pdf/{requestCode}', name: 'results_pdf', methods: ['GET'])]
    public function generatePdf(Request $request, string $requestCode): Response
    {
        $user = $this->sessionUser($request);
        if (!$user) {
            return $this->json(['message' => 'No autorizado'], 401);
        }
        $isPatient = ($user['type'] ?? 'person') === 'person';

        // Validación de pago para pacientes
        if ($isPatient) {
            $paid = $this->results->isPaid($requestCode);
            if (!$paid) {
                return $this->json([
                    'success' => false,
                    'message' => 'No puede descargar este resultado, debe estar al día con el pago',
                ], 402);
            }
        }

        $prevalidated = $request->query->has('prevalidated')
            ? in_array((string) $request->query->get('prevalidated'), ['1', 'true'], true)
            : null;

        // Descarga desde FTP cuando la solicitud tiene archivo publicado (replica downloadFromFtp de V2026)
        $urlParam = (string) $request->query->get('url', '');
        if ($urlParam !== '' && strtolower($urlParam) !== 'no') {
            try {
                $archivo = $this->results->findPacienteExamenes($requestCode, $urlParam);
                if ($archivo) {
                    $pdf = $this->ftpService->downloadPdf($requestCode, $archivo);
                    if ($pdf) {
                        return $this->pdfResponse($pdf['content'], $pdf['filename']);
                    }
                }
            } catch (\Throwable $e) {
                // Si falla FTP, se genera localmente
            }
        }

        try {
            $detail = $this->results->findByRequest($requestCode, $isPatient, $prevalidated);
            if (!$detail) {
                return $this->json(['message' => 'No se encontraron resultados para esta solicitud'], 404);
            }
            $pdf = $this->pdfService->render($detail);
            return $this->pdfResponse($pdf, $requestCode . '.pdf');
        } catch (\Throwable $e) {
            return $this->json(['message' => 'Error generando el PDF'], 500);
        }
    }

    private function pdfResponse(string $content, string $filename): Response
    {
        $resp = new Response($content);
        $resp->headers->set('Content-Type', 'application/pdf');
        $resp->headers->set('Content-Disposition', $resp->headers->makeDisposition('attachment', $filename));
        return $resp;
    }
}
