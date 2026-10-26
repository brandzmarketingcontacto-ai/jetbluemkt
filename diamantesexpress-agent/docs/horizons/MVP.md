# MVP del cliente Windows — DiamantesExpress Agent

Objetivo: conectar DiamantesExpress con un software nativo Windows 24/7 que
controle un emulador Android mediante ADB y gestione entregas autorizadas de
regalos de Free Fire.

## La primera versión debe hacer correctamente estas cinco cosas

1. Conectarse con Horizons: heartbeat + claim de tareas por HTTPS saliente con
   el token de agente (Authorization: Bearer dxa_...).
2. Abrir una sola instancia de BlueStacks (ADB habilitado desde su
   configuración avanzada).
3. Controlar una aplicación Android de prueba con ADB (inicio, taps,
   capturas de pantalla).
4. Interpretar pantallas con OpenAI únicamente cuando aporte valor (análisis
   visual y decisiones), no para arranque/ADB/comprobaciones técnicas.
5. Devolver resultado (outcome: completed|failed|uncertain) y uso de tokens
   (usage: prompt_tokens, completion_tokens, cached_tokens).

## Política de uso de OpenAI

- NO usar OpenAI para: inicio de BlueStacks, conexión ADB, comprobaciones
  técnicas locales.
- Reservar el modelo para: analizar capturas y resolver decisiones visuales.
- Reportar siempre los tokens consumidos en cada resultado.

## Arquitectura

- Comenzar con UNA instancia de BlueStacks. Tras validar estabilidad y
  métricas, ampliar a varias instancias y agentes Windows.
- El agente inicia todas las llamadas (HTTPS saliente). El sandbox de
  Hostinger no recibe conexiones entrantes.
- Persistir el token con DPAPI (Almacén de credenciales de Windows), nunca
  en el repositorio.

## Seguridad y modos

- Modo seguro inicial: simulation (no se toca el juego ni el pedido real).
- supervised: cada entrega requiere aprobación manual del SUPER_ADMIN.
- production: entregas reales, solo con pago verificado y confirmación
  explícita. Un resultado uncertain pasa SIEMPRE a revisión manual.
- Idempotencia: un mismo pedido nunca genera dos tareas (índice UNIQUE).
- Reclamo atómico con lease y reconfirmación de propiedad.

## Límites documentados (no soportados todavía)

- No hay endpoint para crear pedidos desde el agente.
- No hay subida de capturas: la evidencia va inline (JSON, máx. 2 MB);
  se recomienda enviar hashes sha256 y metadatos.
- No hay websockets: el agente hace polling (~10s).
- No hay OAuth ni claves de Garena: el login del emulador se gestiona
  localmente, fuera del servidor.
- El enrutamiento al agente solo aplica a «Pase Booyah».
