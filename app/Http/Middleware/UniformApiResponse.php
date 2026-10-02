<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Exceptions\InvalidCredentials;
use App\Domain\Auth\Exceptions\OAuthClientNotConfigured;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class UniformApiResponse
{
    public function handle(Request $request, Closure $next): JsonResponse
    {
        try {
            $response = $next($request);
        } catch (ValidationException $exception) {
            return $this->error('Los datos enviados no son válidos.', $exception->errors(), 422);
        } catch (InvalidCredentials $exception) {
            return $this->error($exception->getMessage(), null, 401);
        } catch (AuthenticationException) {
            return $this->error('No autenticado.', null, 401);
        } catch (OAuthClientNotConfigured $exception) {
            report($exception);

            return $this->error('El servicio de autenticación no está disponible.', null, 503);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Ocurrió un error interno.', null, 500);
        }

        return $this->normalize($response);
    }

    private function normalize(Response $response): JsonResponse
    {
        $status = $response->getStatusCode();
        $payload = $response instanceof JsonResponse ? $response->getData(true) : null;

        if ($status >= 400) {
            $message = match ($status) {
                401 => 'No autenticado.',
                403 => 'No tiene permisos para realizar esta acción.',
                default => is_array($payload) && isset($payload['message'])
                    ? (string) $payload['message']
                    : 'La solicitud no pudo ser procesada.',
            };
            $errors = is_array($payload) ? ($payload['errors'] ?? null) : null;

            return $this->error($message, $errors, $status);
        }

        $message = is_array($payload) && isset($payload['message'])
            ? (string) $payload['message']
            : 'Solicitud procesada correctamente.';
        $data = is_array($payload) && array_key_exists('data', $payload)
            ? $payload['data']
            : $payload;

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status, $response->headers->all());
    }

    private function error(string $message, mixed $errors, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
