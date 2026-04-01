<?php
declare(strict_types=1);

namespace TranslateDeepL\Core\Exception;

use Exception;
use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends Exception implements ContainerExceptionInterface
{
}
