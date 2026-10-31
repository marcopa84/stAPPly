<?php

namespace App\Markdown;

use App\Services\Backend\Data\Source;
use League\CommonMark\Node\Inline\AbstractInline;

/**
 * L'id di un chunk citato nella risposta, con il passaggio a cui rimanda.
 */
final class Citation extends AbstractInline
{
    public function __construct(public readonly Source $source)
    {
        parent::__construct();
    }
}
