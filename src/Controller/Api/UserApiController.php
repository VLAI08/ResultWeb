<?php

namespace App\Controller\Api;

use App\Service\UsersService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/users')]
class UserApiController extends ApiBaseController
{
    public function __construct(private UsersService $users)
    {
    }

    #[Route('', name: 'api_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->has('recordPerPage')
            ? $request->query->getInt('recordPerPage', 10)
            : $request->query->getInt('limit', 20);
        $data = $this->users->findAll(
            $page,
            $limit,
            (string) $request->query->get('parameter', ''),
            $request->query->get('type') ? (string) $request->query->get('type') : null,
            $request->query->has('active') ? (bool) $request->query->get('active') : null
        );
        return $this->json(['content' => $data['items'], 'totalRecord' => $data['total_count']]);
    }

    #[Route('/{id}', name: 'api_users_get', methods: ['GET'])]
    public function show(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $user = $this->users->findUserById($id);
        if (!$user) {
            return $this->json(['message' => 'Usuario no encontrado'], Response::HTTP_NOT_FOUND);
        }
        $data = $user->toArray();
        unset($data['password']);
        // Correo del último ingreso en WinsisLab si el registro no lo tiene (solo lectura).
        if (trim((string) ($data['email'] ?? '')) === '') {
            $data['email'] = $this->users->emailForUser($user);
        }
        return $this->json($data);
    }

    #[Route('', name: 'api_users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }
        $result = $this->users->create($data);
        if (!$result['success']) {
            return $this->json(['message' => $result['message']], Response::HTTP_CONFLICT);
        }
        $result['user']['password'] = null;
        return $this->json($result['user'], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_users_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->requireSession($request)) {
            return $this->unauthorized();
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'JSON inválido'], Response::HTTP_BAD_REQUEST);
        }
        $result = $this->users->update($id, $data);
        if (!$result['success']) {
            return $this->json(['message' => $result['message']], Response::HTTP_NOT_FOUND);
        }
        $result['user']['password'] = null;
        return $this->json($result['user']);
    }

    #[Route('/{id}', name: 'api_users_delete', methods: ['DELETE'])]
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
}
