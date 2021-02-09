<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\ValueObject\PhpDocNode\Doctrine\Class_;

use Rector\BetterPhpDocParser\Contract\PhpDocNode\TagAwareNodeInterface;
use Rector\BetterPhpDocParser\Printer\ArrayPartPhpDocTagPrinter;
use Rector\BetterPhpDocParser\Printer\TagValueNodePrinter;
use Rector\BetterPhpDocParser\ValueObject\PhpDocNode\Doctrine\AbstractDoctrineTagValueNode;

final class UniqueConstraintTagValueNode extends AbstractDoctrineTagValueNode implements TagAwareNodeInterface
{
    /**
     * @var string|null
     */
    private $tag;

    public function __construct(ArrayPartPhpDocTagPrinter $arrayPartPhpDocTagPrinter, TagValueNodePrinter $tagValueNodePrinter, array $items, ?string $content = null, ?string $originalTag = null)
    {
        $this->tag = $originalTag;
        parent::__construct($arrayPartPhpDocTagPrinter, $tagValueNodePrinter, $items, $content);
    }

    public function getTag(): string
    {
        return $this->tag ?: $this->getShortName();
    }

    public function getShortName(): string
    {
        return '@ORM\UniqueConstraint';
    }
}
