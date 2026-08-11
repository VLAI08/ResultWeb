<?php

namespace App\Controller\Api;

use App\Service\FileService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Servicio de archivos (replica file module de lab-results-api).
 * - GET /api/files?path=...  → sirve el archivo (solo dentro de public/static, sin sesión requerida)
 * - POST /api/files          → guarda un archivo y devuelve su ruta relativa (requiere sesión)
 */
#[Route('/api')]
class FileApiController extends ApiBaseController
{
    public function __construct(private FileService $files)
    {
    }

    #[Route('/files', name: 'api_files_get', methods: ['GET'])]
    public function find(Request $request): Response
    {
        $path = (string) $request->query->get('path', '');
        $file = $this->files->findFile($path);
        if (!$file) {
            return $this->json(['message' => 'Archivo no encontrado'], Response::HTTP_NOT_FOUND);
        }
        $response = new Response((string) file_get_contents($file));
        $mime = (string) mime_content_type($file);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    #[Route('/files', name: 'api_files_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file || !$file->isValid()) {
            return $this->json(['message' => 'No se recibió ningún archivo'], Response::HTTP_BAD_REQUEST);
        }
        $folder = (string) $request->request->get('path', $request->query->get('path', 'upload'));
        try {
            $url = $this->files->saveUpload($file, $folder);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        return $this->json(['success' => true, 'url' => $url]);
    }

    #[Route('/upload', name: 'api_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file || !$file->isValid()) {
            return $this->json(['message' => 'No se recibió ningún archivo'], Response::HTTP_BAD_REQUEST);
        }
        $folder = (string) $request->request->get('folder', 'upload');
        try {
            $url = $this->files->saveUpload($file, $folder);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
        return $this->json(['success' => true, 'url' => $url]);
    }
}
