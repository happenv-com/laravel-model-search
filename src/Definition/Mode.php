<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

enum Mode: string
{
    case Exact = 'exact';
    case Prefix = 'prefix';
    case Suffix = 'suffix';
    case Infix = 'infix';
    case Fuzzy = 'fuzzy';
    case FullText = 'fulltext';
}
