<?php

namespace GlpiPlugin\Opmanager\OpManager;

/** Any failure talking to OpManager. Messages are always redacted (no apiKey). */
final class ClientException extends \RuntimeException
{
}
