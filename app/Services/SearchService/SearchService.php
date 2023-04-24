<?php

namespace App\Services\SearchService;

use App\Services\BaseService\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SearchService extends BaseService
{
    /**
     * @throws ValidationException
     */
    public function search(Request $request): JsonResponse|AnonymousResourceCollection|Collection|null
    {
        $validator = $this->validateRequest($request);

        if ($validator->fails()) {
            return $this->sendMessageWithError($validator->errors()->first(), 422);//TODO make sendError method static
        }
        $lang = $request->input('lang');
        $searchData = $validator->validated();


        $preparedQuery = $this->prepareQuery($searchData['query']);
        $type = $searchData['type'] ?? null;
        $cityId = $searchData['city'] ?? null;

        return $this->getResults($type, $preparedQuery, $cityId, $lang);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Validation\Validator|\Illuminate\Validation\Validator
     */
    private function validateRequest(Request $request): \Illuminate\Contracts\Validation\Validator|\Illuminate\Validation\Validator
    {
        return Validator::make($request->all(), [
            'query' => ['required', 'min:3', function ($attribute, $value, $fail) {
                $coll = collect(explode(" ", $value));

                $filtered = $coll->filter(function ($v) {
                    return 3 > mb_strlen($v);
                });

                if ($filtered->isNotEmpty()) {
                    $fail('Отдельно взятые слова должны состоять из трёх или более символов.');
                }
            },],
            'city' => ['sometimes', 'exists:cities,id'],
            'type' => ['sometimes', Rule::in(['attractions', 'selections', 'tags', 'trips'])],
        ], [
            'query.required' => 'Отсутствует ключевое слово.',
            'query.min' => 'Введите не менее трёх символов.',
            'city.exists' => 'Город не найден.',
            'type.in' => 'Указан несуществующий тип данных.',
        ]);
    }

    /**
     * Удаляем "лишние" символы и заменяем пробелы на "|" для поиска в БД.
     *
     * @param string $rawQuery
     * @return string
     */
    private function prepareQuery(string $rawQuery): string
    {
        return preg_replace('/[\s-]+/', '|', preg_replace('/[^\w\s-]+/u', '', $rawQuery));
    }

    /**
     * @param string|null $type
     * @param string $preparedQuery
     * @param int|null $cityId
     * @param string|null $lang
     * @return AnonymousResourceCollection|Collection|null
     */
    private function getResults(?string $type, string $preparedQuery, ?int $cityId, ?string $lang): AnonymousResourceCollection|Collection|null
    {
        if ($type) {
            $results = $this->getSpecificTypeResults($type, $preparedQuery, $cityId, $lang);
        } else {
            $results = $this->getAllResultsAndMergeIt($preparedQuery, $cityId, $lang);
        }
        return $results;
    }

    /**
     * @param $type
     * @param string $preparedQuery
     * @param $cityId
     * @param string $lang
     * @return AnonymousResourceCollection|Collection
     */
    private function getSpecificTypeResults($type, string $preparedQuery, $cityId, string $lang): AnonymousResourceCollection|Collection
    {
        return match ($type) {
            "trips" => $this->getResultsByType($type, $preparedQuery, $cityId, $lang),
            "tags" => $this->getTagResults($preparedQuery),
        };
//        switch ($type) {
//            case 'attractions':
//            case 'selections':
//            case 'trips':
//                return $this->getResultsByType($type, $preparedQuery, $cityId, $request->lang);
//            case 'tags':
//                return $this->getTagResults($preparedQuery);
//        }
    }

    /**
     * @param string $type
     * @param string $query
     * @param int|null $cityId
     * @param string|null $language
     * @return AnonymousResourceCollection|Collection
     */
    private function getResultsByType(string $type, string $query, ?int $cityId, ?string $language): AnonymousResourceCollection|Collection
    {
        $modelClassName = 'App\\' . Str::ucfirst(Str::singular($type));
        $resourceClassName = 'App\\Http\\Resources\\' . Str::ucfirst($type) . 'Resource';

        list($titleColumn, $descriptionColumn, $resourceClassName) = $this->getColumnNamesAndResourceClassNameByLanguage($language, $resourceClassName);

        /** @var Model $modelClassName */
        $records = $modelClassName::query()->when($cityId !== null, function ($q) use ($cityId) {
            return $q->where('city_id', $cityId);
        })
            ->where(function ($q) use ($query, $descriptionColumn, $titleColumn) {
                $q->where($titleColumn, '~*', $query)
                    ->orWhere($descriptionColumn, '~*', $query);//TODO change to FULLTEXT search
            })
            ->with('city.region')
            ->get();

        /** @var JsonResource $resourceClassName */
        $collection = $resourceClassName::collection($records);

        return $cityId !== null ? $collection : $collection->collection->groupBy('city.region.name');
    }

    /**
     * @param string|null $language
     * @param string $resourceClassName
     * @return string[]
     */
    private function getColumnNamesAndResourceClassNameByLanguage(?string $language, string $resourceClassName): array
    {
        $titleColumn = 'title';
        $descriptionColumn = 'description';

        if (isset($language) && $language === 'En') {
            $titleColumn .= '_en';
            $descriptionColumn .= '_en';
            $resourceClassName = preg_replace("/(\\\)(\\w+$)/i", '\En$1$2En', $resourceClassName);
        }

        return [$titleColumn, $descriptionColumn, $resourceClassName];
    }

    /**
     * @param string $query
     * @return AnonymousResourceCollection
     */
    private function getTagResults(string $query): AnonymousResourceCollection
    {
        $records = Tag::query()->where('title', '~*', $query)->get(['id', 'title']);

        return TagResource::collection($records);
    }

    /**
     * @param string $preparedQuery
     * @param int|null $cityId
     * @param string|null $language
     * @return Collection
     */
    private function getAllResultsAndMergeIt(string $preparedQuery, ?int $cityId, ?string $language): Collection
    {
        $attractions = $this->getResultsByType('attractions', $preparedQuery, $cityId, $language);
        $selections = $this->getResultsByType('selections', $preparedQuery, $cityId, $language);
        $tags = $this->getTagResults($preparedQuery);
        $trips = $this->getResultsByType('trips', $preparedQuery, $cityId, $language);

        return $this->mergeResults($cityId, $attractions, $selections, $trips, $tags);
    }

    /**
     * @param int|null $cityId
     * @param AnonymousResourceCollection|Collection $attractions
     * @param AnonymousResourceCollection|Collection $selections
     * @param AnonymousResourceCollection|Collection $trips
     * @param AnonymousResourceCollection $tags
     * @return Collection
     */
    private function mergeResults(?int $cityId, $attractions, $selections, $trips, AnonymousResourceCollection $tags): Collection
    {
        if ($cityId === null) {
            return $attractions->map(function ($item) {
                return ['attractions' => $item];
            })->mergeRecursive($selections->map(function ($item) {
                return ['selections' => $item];
            }))->mergeRecursive($trips->map(function ($item) {
                return ['trips' => $item];
            }))->mergeRecursive($tags->collection->isNotEmpty() ? ['tags' => $tags] : null);
        }

        return collect(compact('attractions', 'selections', 'trips', 'tags'));
    }
}
