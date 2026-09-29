<?php

namespace App\Services\OpenFoodFacts;

class SearchPage
{
    public function __construct(
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $count,
        public readonly array $products,
    ) {}

    public function isLast(): bool
    {
        return count($this->products) < $this->pageSize
            || $this->page * $this->pageSize >= $this->count;
    }
}
