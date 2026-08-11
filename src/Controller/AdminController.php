<?php

namespace App\Controller;

use App\Service\DomainsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends AbstractController
{
    public function __construct(private DomainsService $domains)
    {
    }

    private const ACTIONS = [
        'patients' => 'gestionar_paciente',
        'clients' => 'gestionar_cliente',
        'signatures' => 'firmas',
        'configuration' => 'gestionar_parametros',
        'results' => 'buscar_resultados',
    ];

    private const ACTIVE = [
        'pacientes' => 'patients',
        'resultados' => 'results',
        'clientes' => 'clients',
        'firmas' => 'signatures',
        'configuracion' => 'configuration',
    ];

    private function sessionUser(Request $request): ?array
    {
        $user = $request->getSession()->get('user');
        return is_array($user) ? $user : null;
    }

    private function userHasAction(?array $user, string $action): bool
    {
        if (!$user) {
            return false;
        }
        if (($user['type'] ?? '') === 'admin') {
            return true;
        }
        return in_array($action, (array) ($user['actions'] ?? []), true);
    }

    private function renderModule(Request $request, string $view, string $requiredAction): Response
    {
        $user = $this->sessionUser($request);
        if (!$user) {
            return $this->render('security/login.html.twig');
        }
        if (!$this->userHasAction($user, $requiredAction)) {
            return $this->redirectToRoute('root');
        }
        $identClient = $this->domains->findOneActiveByName('IDENTIFICATION_TYPE_CLIENT');
        return $this->render('admin/' . $view . '.html.twig', [
            'user' => $user,
            'active' => self::ACTIVE[$view] ?? '',
            'identification_types' => $this->domains->listDomainsActive('identificationtype'),
            'identification_type_client' => $identClient['valor'] ?? 'NI',
        ]);
    }

    /**
     * Módulos del panel administrable (sidebar de la versión nueva).
     */
    #[Route('/admin/pacientes', name: 'admin_patients', methods: ['GET'])]
    public function patients(Request $request): Response
    {
        return $this->renderModule($request, 'pacientes', self::ACTIONS['patients']);
    }

    #[Route('/admin/resultados', name: 'admin_results', methods: ['GET'])]
    public function results(Request $request): Response
    {
        return $this->renderModule($request, 'resultados', self::ACTIONS['results']);
    }

    #[Route('/admin/clientes', name: 'admin_clients', methods: ['GET'])]
    public function clients(Request $request): Response
    {
        return $this->renderModule($request, 'clientes', self::ACTIONS['clients']);
    }

    #[Route('/admin/firmas', name: 'admin_signatures', methods: ['GET'])]
    public function signatures(Request $request): Response
    {
        return $this->renderModule($request, 'firmas', self::ACTIONS['signatures']);
    }

    #[Route('/admin/configuracion', name: 'admin_configuration', methods: ['GET'])]
    public function configuration(Request $request): Response
    {
        return $this->renderModule($request, 'configuracion', self::ACTIONS['configuration']);
    }

    /**
     * Stub de carga de resultados desde archivo. Recibe multipart/form-data con 'file'.
     */
    #[Route('/upload_result/resultados', name: 'admin_upload_result', methods: ['POST'])]
    public function uploadResultados(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (!$user || (($user['type'] ?? '') !== 'admin')) {
            return $this->json(['session' => false]);
        }
        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['state' => 0, 'msg' => 'Archivo requerido']);
        }
        return $this->json(['state' => 1, 'msg' => 'Archivo recibido: ' . $file->getClientOriginalName()]);
    }

    /**
     * Stub de carga de resultados desde FTP (acción directa sin payload).
     */
    #[Route('/upload_result_ftp', name: 'admin_upload_result_ftp', methods: ['POST'])]
    public function uploadResultadosFtp(Request $request): JsonResponse
    {
        $user = $this->sessionUser($request);
        if (!$user || (($user['type'] ?? '') !== 'admin')) {
            return $this->json(['session' => false]);
        }
        return $this->json(['state' => 1, 'msg' => 'Procesamiento FTP iniciado']);
    }
}
