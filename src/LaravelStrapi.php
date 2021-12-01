<?php

namespace Dbfx\LaravelStrapi;

use Dbfx\LaravelStrapi\Exceptions\NotFound;
use Dbfx\LaravelStrapi\Exceptions\PermissionDenied;
use Dbfx\LaravelStrapi\Exceptions\UnknownError;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LaravelStrapi
{
    public const CACHE_KEY = 'laravel-strapi-cache';

    private $strapiUrl;
    private $cacheTime;

    public function __construct()
    {
        $this->strapiUrl = config('strapi.url');
        $this->cacheTime = config('strapi.cacheTime');
    }

    public function collection(string $type, $sortKey = 'id', $sortOrder = 'DESC', $limit = 20, $start = 0, $fullUrls = true, $populate = null): Collection
    {
        $url      = $this->strapiUrl;
        $cacheKey = self::CACHE_KEY . '.collection.' . $type . '.' . $sortKey . '.' . $sortOrder . '.' . $limit . '.' . $start . '.'. $populate;

        // Fetch and cache the collection type
        $collection = Cache::remember($cacheKey, $this->cacheTime, function () use ($url, $type, $sortKey, $sortOrder, $limit, $start, $populate) {
            $response = Http::get($url . '/' . $type . '?_sort=' . $sortKey . ':' . $sortOrder . '&_limit=' . $limit . '&_start=' . $start . ($populate ? '&populate='.$populate : ''));

            return $response->json();
        });

        if (isset($collection['statusCode']) && $collection['statusCode'] === 403) {
            Cache::forget($cacheKey);

            throw new PermissionDenied('Strapi returned a 403 Forbidden');
        }

        if (!is_array($collection)) {
            Cache::forget($cacheKey);

            if ($collection === null) {
                throw new NotFound('The requested single entry (' . $type . ') was null');
            }

            throw new UnknownError('An unknown Strapi error was returned');
        }

        // Replace any relative URLs with the full path
        if ($fullUrls) {
            foreach ($collection as $key => $item) {
                if (!is_string($key)) {
                    continue;
                }
                foreach (array_keys($item) as $subKey) {
                    $collection[$key][$subKey] = preg_replace('/!\[(.*)\]\((.*)\)/', '![$1](' . config('strapi.url') . '$2)', $collection[$key][$subKey]);
                }
            }
        }

        return collect($collection['data'])->map(fn ($item) => $this->transformData($item, $populate));
    }

    public function transformData($item, $populate = null)
    {
        if (!isset($item['id']) || !isset($item['attributes'])) {
            return $item;
        }
        $keys = explode(',', $populate);

        $result       = $item['attributes'];
        $result['id'] = $item['id'];

        foreach ($keys as $key) {
            if (isset($result[$key])) {
                $result[$key] = (object)$this->transformData($result[$key]['data'] ?? $result[$key]);
            }
        }

        return (object) $result;
    }

    public function collectionCount(string $type): int
    {
        $url = $this->strapiUrl;

        return Cache::remember(self::CACHE_KEY . '.collectionCount.' . $type, $this->cacheTime, function () use ($url, $type) {
            $response = Http::get($url . '/' . $type . '/count');

            return $response->json();
        });
    }

    public function entry(string $type, int $id, $fullUrls = true): array
    {
        $url      = $this->strapiUrl;
        $cacheKey = self::CACHE_KEY . '.entry.' . $type . '.' . $id;

        $entry = Cache::remember($cacheKey, $this->cacheTime, function () use ($url, $type, $id) {
            $response = Http::get($url . '/' . $type . '/' . $id);

            return $response->json();
        });

        if (isset($entry['statusCode']) && $entry['statusCode'] === 403) {
            Cache::forget($cacheKey);

            throw new PermissionDenied('Strapi returned a 403 Forbidden');
        }

        if (!isset($entry['id'])) {
            Cache::forget($cacheKey);

            if ($entry === null) {
                throw new NotFound('The requested single entry (' . $type . ') was null');
            }

            throw new UnknownError('An unknown Strapi error was returned');
        }

        if ($fullUrls) {
            foreach ($entry as $key => $item) {
                if (!is_string($item) || !is_string($item)) {
                    continue;
                }
                $entry[$key] = preg_replace('/!\[(.*)\]\((.*)\)/', '![$1](' . config('strapi.url') . '$2)', $item);
            }
        }

        return $entry;
    }

    public function entriesByField(string $type, string $fieldName, $fieldValue, $fullUrls = true, $populate = null): Collection
    {
        $url      = $this->strapiUrl;
        $cacheKey = self::CACHE_KEY . '.entryByField.' . $type . '.' . $fieldName . '.' . $fieldValue . '.' . $populate;

        $entries = Cache::remember($cacheKey, $this->cacheTime, function () use ($url, $type, $fieldName, $fieldValue, $populate) {
            $response = Http::get($url . '/' . $type . '?' . $fieldName . '=' . $fieldValue . ($populate ? '&populate='.$populate : ''));

            return $response->json();
        });

        if (isset($entries['statusCode']) && $entries['statusCode'] === 403) {
            Cache::forget($cacheKey);

            throw new PermissionDenied('Strapi returned a 403 Forbidden');
        }

        if (!is_array($entries)) {
            Cache::forget($cacheKey);

            if ($entries === null) {
                throw new NotFound('The requested entries by field (' . $type . ') were not found');
            }

            throw new UnknownError('An unknown Strapi error was returned');
        }

        if ($fullUrls) {
            foreach ($entries as $key => $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach (array_keys($item) as $subKey) {
                    $entries[$key][$subKey] = preg_replace('/!\[(.*)\]\((.*)\)/', '![$1](' . config('strapi.url') . '$2)', $entries[$key][$subKey]);
                }
            }
        }

        return  collect($entries['data'])->map(fn ($item) => $this->transformData($item, $populate));
    }

    public function single(string $type, string $pluck = null, $fullUrls = true)
    {
        $url      = $this->strapiUrl;
        $cacheKey = self::CACHE_KEY . '.single.' . $type;

        // Fetch and cache the collection type
        $single = Cache::remember($cacheKey, $this->cacheTime, function () use ($url, $type) {
            $response = Http::get($url . '/' . $type);

            return $response->json();
        });

        if (isset($single['statusCode']) && $single['statusCode'] === 403) {
            Cache::forget($cacheKey);

            throw new PermissionDenied('Strapi returned a 403 Forbidden');
        }

        if (! isset($single['id'])) {
            Cache::forget($cacheKey);

            if ($single === null) {
                throw new NotFound('The requested single entry (' . $type . ') was null');
            }

            throw new UnknownError('An unknown Strapi error was returned');
        }

        // Replace any relative URLs with the full path
        if ($fullUrls) {
            foreach ($single as $key => $item) {
                if (!is_string($item)) {
                    continue;
                }
                $single[$key] = preg_replace('/!\[(.*)\]\((.*)\)/', '![$1](' . config('strapi.url') . '$2)', $item);
            }
        }

        if ($pluck !== null && isset($single[$pluck])) {
            return $single[$pluck];
        }

        return $single;
    }
}
