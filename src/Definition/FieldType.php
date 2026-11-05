<?php

declare(strict_types=1);

namespace Happenv\ModelSearch\Definition;

enum FieldType: string
{
    case Key = 'key';
    case Name = 'name';
    case Via = 'via';
    case Text = 'text';
}
