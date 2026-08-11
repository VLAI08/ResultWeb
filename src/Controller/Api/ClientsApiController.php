<?php

namespace App\Controller\Api;

use App\Service\FileService;
use App\Service\UsersService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Clientes = usuarios con type 'company' (replica user/client de lab-results-api).
 * Creación/edición acepta multipart: files[] (urlImage, footer) + body (JSON).
 */
#[Route('/api/clients')]
class ClientsApiController extends ApiBaseController
{
    public function __construct(private UsersService $users, private FileService $files)
    {
    }

    #[Route('', name: 'api_clients_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->has('recordPerPage')
            ? $request->query->getInt('recordPerPage', 10)
            : $request->query->getInt('limit', 10);
        $data = $this->users->findAll(
            $page,
            $limit,
            (string) $request->query->get('parameter', ''),
            'company',
            $request->query->has('active') ? (bool) $request->query->get('active') : null
        );
        return $this->json(['content' => $data['items'], 'totalRecord' => $data['total_count']]);
    }

    #[Route('/{id}', name: 'api_clients_get', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $user = $this->users->findUserById($id);
        if (!$user || ($user->getType() ?? '') !== 'company') {
            return $this->json(['message' => 'Cliente no encontrado'], Response::HTTP_NOT_FOUND);
        }
        $data = $user->toArray();
        unset($data['password']);
        // Correo del último ingreso en WinsisLab si el registro no lo tiene (solo lectura).
        if (trim((string) ($data['email'] ?? '')) === '') {
            $data['email'] = $this->users->emailForUser($user);
        }
        return $this->json($data);
    }

    #[Route('', name: 'api_clients_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = $this->extractBody($request);
        if (!$data) {
            return $this->json(['message' => 'Body inválido'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $data = $this->handleUploads($request, $data, 'create');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $data['type'] = 'company';
        $data['type_admin'] = $data['type_admin'] ?? 'buscar_resultados,actualizar_datos_cliente';
        $data['download_options'] = $data['download_options'] ?? $data['downloadOption'] ?? 'si';
        $data['logo_options'] = $data['logo_options'] ?? $data['logoOption'] ?? '';
        $data['identification'] = $data['identification'] ?? $data['identificationNumber'] ?? '';
        $data['identificationtype'] = $data['identificationtype'] ?? $data['identificationType'] ?? 'NI';
        $data['names'] = $data['names'] ?? $data['name'] ?? '';
        $data['phones'] = $data['phones'] ?? $data['phone'] ?? '';
        $data['contact'] = $data['contact'] ?? '';
        $data['phone_contact'] = $data['phone_contact'] ?? $data['contactPhone'] ?? '';

        $result = $this->users->create($data);
        if (!$result['success']) {
            return $this->json(['message' => $result['message']], Response::HTTP_CONFLICT);
        }
        $result['user']['password'] = null;
        return $this->json($result['user'], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_clients_update', methods: ['PATCH', 'PUT'])]
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = $this->extractBody($request);
        if (!$data) {
            return $this->json(['message' => 'Body inválido'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $data = $this->handleUploads($request, $data, 'update');
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // Aliasing de campos del contrato Angular
        $map = [
            'identificationNumber' => 'identification',
            'name' => 'names',
            'contactPhone' => 'phone_contact',
            'downloadOption' => 'download_options',
            'logoOption' => 'logo_options',
            'phone' => 'phones',
        ];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data) && !array_key_exists($to, $data)) {
                $data[$to] = $data[$from];
            }
        }

        $result = $this->users->update($id, $data);
        if (!$result['success']) {
            return $this->json(['message' => $result['message']], Response::HTTP_NOT_FOUND);
        }
        $result['user']['password'] = null;
        return $this->json($result['user']);
    }

    #[Route('/{id}', name: 'api_clients_update_partial', methods: ['PUT'])]
    public function updatePartial(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = $this->extractBody($request);
        if (!$data) {
            return $this->json(['message' => 'Body inválido'], Response::HTTP_BAD_REQUEST);
        }
        // Replica UpdatePartialClientDto de V2026 ("Editar mis datos" del cliente)
        $map = [
            'identificationNumber' => 'identification',
            'name' => 'names',
            'phone' => 'phones',
            'contactPhone' => 'phone_contact',
        ];
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data) && !array_key_exists($to, $data)) {
                $data[$to] = $data[$from];
            }
        }

        $result = $this->users->update($id, $data);
        if (!$result['success']) {
            return $this->json(['message' => $result['message']], Response::HTTP_NOT_FOUND);
        }
        $result['user']['password'] = null;
        return $this->json($result['user']);
    }

    #[Route('/{id}', name: 'api_clients_delete', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $result = $this->users->deactivate($id);
        if (!$result['success']) {
            return $this->json(['message' => $result['message']], Response::HTTP_NOT_FOUND);
        }
        return $this->json(['success' => true]);
    }

    /**
     * Extrae el body JSON del request.
     * Para PATCH multipart PHP no llena $_POST: parsea manualmente el contenido crudo.
     */
    private function extractBody(Request $request): ?array
    {
        if ($request->request->has('body')) {
            $data = json_decode((string) $request->request->get('body'), true);
        } else {
            $contentType = (string) $request->headers->get('Content-Type', '');
            $raw = (string) $request->getContent();
            if ($raw !== '' && str_starts_with($contentType, 'multipart/form-data')) {
                $parts = $this->parseMultipart($raw, $contentType);
                $request->attributes->set('_multipart_fields', $parts['fields']);
                $request->attributes->set('_multipart_files', $parts);
                $data = json_decode((string) ($parts['fields']['body'] ?? ''), true);
            } else {
                $data = json_decode($raw, true);
            }
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Parseador multipart/form-data manual (PHP no rellena $_POST en PATCH).
     * Devuelve ['fields' => [nombre => valor], 'files' => [nombre => [UploadedFile]]].
     */
    private function parseMultipart(string $content, string $contentType): array
    {
        $fields = [];
        $files = [];
        if (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $m)) {
            $boundary = $m[1] ?: $m[2];
        } elseif (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $content, $m)) {
            $boundary = $m[1] ?: $m[2];
        } else {
            return ['fields' => $fields, 'files' => $files];
        }
        $blocks = preg_split('/--' . preg_quote($boundary, '/') . '\r?\n/', $content);
        foreach ($blocks as $block) {
            if (trim($block) === '' || trim($block) === '--') {
                continue;
            }
            $headerEnd = strpos($block, "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }
            $headers = substr($block, 0, $headerEnd);
            $body = substr($block, $headerEnd + 4);
            if (substr($body, -2) === "\r\n") {
                $body = substr($body, 0, -2);
            }
            // El último bloque conserva el cierre "--boundary--"; se elimina para no contaminar el valor.
            $closing = '--' . $boundary . '--';
            if (str_ends_with($body, $closing)) {
                $body = substr($body, 0, -strlen($closing));
            }
            if (!preg_match('/name="([^"]+)"/', $headers, $hm)) {
                continue;
            }
            $name = $hm[1];
            if (preg_match('/filename="([^"]*)"/', $headers, $fm)) {
                $filename = $fm[1];
                $mime = 'application/octet-stream';
                if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $cm)) {
                    $mime = trim($cm[1]);
                }
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $tmpPath = sys_get_temp_dir() . '/' . uniqid('oc_upload_', true) . '.' . ($ext ?: 'bin');
                file_put_contents($tmpPath, $body);
                $files[$name][] = new UploadedFile($tmpPath, $filename, $mime, UPLOAD_ERR_OK, true);
            } else {
                $fields[$name] = $body;
            }
        }
        return ['fields' => $fields, 'files' => $files];
    }

    /**
     * Guarda los archivos files[] (header y footer) y asigna urlimg/footer en $data.
     */
    private function handleUploads(Request $request, array $data, string $mode): array
    {
        $parsedFiles = $request->attributes->get('_multipart_files');
        if ($parsedFiles !== null) {
            $files = [];
            foreach (($parsedFiles['files'] ?? []) as $group) {
                foreach ((array) $group as $f) {
                    if ($f instanceof UploadedFile) {
                        $files[] = $f;
                    }
                }
            }
        } else {
            /** @var UploadedFile[] $files */
            $files = $request->files->all('files');
        }
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile && $f->isValid()));
        if (!$files) {
            return $data;
        }
        $folder = 'upload/logos';
        $saved = [];
        foreach ($files as $file) {
            try {
                $saved[] = $this->files->saveUpload($file, $folder);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException($e->getMessage());
            }
            $tmp = $file->getPathname();
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        // files[0] = header (urlimg), files[1] = footer
        if (isset($saved[0])) {
            $data['urlimg'] = $saved[0];
        }
        if (isset($saved[1])) {
            $data['footer'] = $saved[1];
        }
        return $data;
    }
}
