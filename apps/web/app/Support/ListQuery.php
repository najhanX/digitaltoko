<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ListQuery
{
    public array $filters;

    public function __construct(private Request $request, array $choices = [], int $defaultSize = 20, private string $prefix = '')
    {
        $text = $request->query($prefix.'q', '');
        $size = $request->query($prefix.'per_page', $defaultSize);
        $this->filters = ['q' => is_string($text) ? mb_substr(trim($text), 0, 120) : '',
            'per_page' => is_scalar($size) && in_array((string) $size, ['5', '10', '20', '50'], true) ? (int) $size : $defaultSize];
        foreach ($choices as $key => $allowed) {
            $value = $request->query($prefix.$key, '');
            $this->filters[$key] = is_string($value) && in_array($value, $allowed, true) ? $value : '';
        }
    }

    public function apply(Builder $query, array $searchColumns, array $filterColumns = []): Builder
    {
        if ($this->filters['q'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->filters['q']).'%';
            $query->where(function (Builder $nested) use ($searchColumns, $pattern) {
                foreach ($searchColumns as $column) {
                    $wrapped = $nested->getQuery()->getGrammar()->wrap($column);
                    $nested->orWhereRaw($wrapped." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        foreach ($filterColumns as $key => $column) {
            if (($this->filters[$key] ?? '') !== '') {
                $query->where($column, $this->filters[$key]);
            }
        }

        return $query;
    }

    public function paginate(Builder $query): LengthAwarePaginator
    {
        $key = $this->prefix.'page';
        $raw = $this->request->query($key, 1);
        $page = is_scalar($raw) && ctype_digit((string) $raw) ? min(1000000, max(1, (int) $raw)) : 1;
        $result = $query->paginate($this->filters['per_page'], ['*'], $key, $page)->withQueryString();
        if ($page > $result->lastPage()) {
            $result = $query->paginate($this->filters['per_page'], ['*'], $key, $result->lastPage())->withQueryString();
        }

        return $result;
    }

    public static function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];
    }
}
