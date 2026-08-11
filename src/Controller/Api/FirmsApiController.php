<?php

namespace App\Controller\Api;

use App\Service\FirmsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/firms')]
class FirmsApiController extends ApiBaseController
{
    public function __construct(private FirmsService $firms)
    {
    }

    #[Route('', name: 'api_firms_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = $this->firms->findAll(
            $request->query->getInt('page', 1),
            $request->query->getInt('limit', 20),
            (string) $request->query->get('parameter', '')
        );
        return $this->json($data);
    }

    #[Route('/{id}', name: 'api_firms_get', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $firm = $this->firms->find($id);
        if (!$firm) {
            return $this->json(['message' => 'Firma no encontrada'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($firm);
    }

    #[Route('', name: 'api_firms_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }
        $firm = $this->firms->create(
            (string) ($data['code'] ?? ''),
            (string) ($data['url'] ?? ''),
            (string) ($data['code_company'] ?? ''),
            (bool) ($data['active'] ?? true)
        );
        return $this->json($firm, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_firms_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }
        $firm = $this->firms->update($id, $data);
        if (!$firm) {
            return $this->json(['message' => 'Firma no encontrada'], Response::HTTP_NOT_FOUND);
        }
        return $this->json($firm);
    }

    #[Route('/{id}', name: 'api_firms_delete', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $this->firms->deactivate($id);
        return $this->json(['success' => true]);
    }
}
