"""Errores del cliente de la API de DiamantesExpress."""


class ApiError(Exception):
    def __init__(self, message: str, status: int | None = None, body: object = None):
        super().__init__(message)
        self.status = status
        self.body = body


class ApiUnavailable(ApiError):
    """Sin Internet, timeout, DNS o error 5xx: reintentar con backoff."""


class AuthError(ApiError):
    """Token inválido, expirado o revocado (401/403): detener y pedir nuevo emparejamiento."""


class ConflictError(ApiError):
    """409: el trabajo ya fue reclamado o ya no pertenece a este agente."""


class ContractError(ApiError):
    """El contrato OpenAPI no define la operación requerida o la respuesta no es válida."""


class InsecureUrlError(ApiError):
    """Se intentó usar HTTP sin TLS fuera de localhost."""
