<?php

namespace App\Services;

use App\Exceptions\OzonForbiddenException;
use App\Exceptions\OzonParserException;
use App\Models\Review;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class OzonParser
{
    const COUNT_PAGES = 3;
    const BASE_URL = 'https://www.ozon.ru/api/entrypoint-api.bx/page/json/v2?url=';

    const PRODUCT_BASE_URL = '/product/';
    const SEARCH_BASE_URL = '/category/zhenskaya-odezhda-7501/?';
    const ANSWER_TO_COMMENT_URL = 'https://www.ozon.ru/api/composer-api.bx/_action/rpGetCommentsByReviewUuid';

    const ONE_MONTH = 30 * 24 * 60 * 60;
    const DELAY = 2;
    const REDIS_PRODUCTS_KEY = 'ozon_products';

    protected $cookieJar;

    /**
     * @return void
     *
     * @throws OzonForbiddenException
     * @throws OzonParserException
     */
    public function start(): void
    {
        DB::table('reviews')->truncate();

        for ($page = 2; $page <= self::COUNT_PAGES + 1; $page++) {
            $products = $this->sendProductListRequest($page);

            $this->savePageTags($products['items']);

            $products = Redis::hGetAll(self::REDIS_PRODUCTS_KEY);

            foreach ($products as $product) {
                $this->parseProduct($product);
            }
        }
    }

    /**
     * @param $page
     *
     * @return array
     *
     * @throws OzonForbiddenException
     * @throws OzonParserException
     */
    protected function sendProductListRequest($page): array
    {
        $this->cookieJar = new CookieJar();
        Http::withOptions(['cookies' => $this->cookieJar])->get('https://www.ozon.ru');

        $params = [
            'layout_container' => 'categorySearchMegapagination',
            'layout_page_index' => $page,
            'page' => $page,
        ];

        $queryString = http_build_query($params);

        $urlParam = self::SEARCH_BASE_URL . $queryString;
        $urlParam = urlencode($urlParam);
        $response = Http::withOptions(['cookies' => $this->cookieJar])->get(self::BASE_URL . $urlParam);

        if ($response->status() === 403) {
            throw new OzonForbiddenException();
        }

        $responseJson = $response->json();
        $searchKey = $this->searchJsonBoxKey($responseJson, 'searchResultsV2');

        if ($searchKey === null) {
            throw new OzonParserException();
        }

        $products = json_decode($response['widgetStates'][$searchKey], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OzonParserException();
        }

        return $products;
    }

    /**
     * @param array $products
     *
     * @return void
     */
    protected function savePageTags(array $products): void
    {
        Redis::del(self::REDIS_PRODUCTS_KEY);
        foreach ($products as $index => $product) {
            $link = $product['action']['link'];
            $pattern = '/(\/product\/)([a-zA-Z0-9\-]+)(\/\?)/';
            preg_match($pattern, $link, $matches);
            $tag = $matches[2];

            Redis::hSet(self::REDIS_PRODUCTS_KEY, $index, $tag);
        }
    }

    /**
     * @param string $tag
     *
     * @return void
     *
     * @throws OzonForbiddenException
     * @throws OzonParserException
     */
    public function parseProduct(string $tag)
    {
        $urlParam = null;
        $page = 1;
        Redis::del($tag);

        while (true) {
            $link = $this->generateLink($urlParam, $tag, $page);
            $urlParam = $this->parseProductReviews($link, $tag);
            if ($urlParam === null) {
                break;
            }
            $page = $page + 1;
        }
    }

    /**
     * @param string $link
     * @param string $tag
     *
     * @return string|null
     *
     * @throws OzonForbiddenException
     * @throws OzonParserException
     */
    public function parseProductReviews(string $link, string $tag): ?string
    {
        echo $link . PHP_EOL . PHP_EOL;

        $response = Http::get($link);
        if ($response->status() === 403) {
            throw new OzonForbiddenException();
        }
        $responseJson = $response->json();

        $searchKey = $this->searchJsonBoxKey($responseJson, 'webListReviews');

        if ($searchKey === null) {
            return null;
        }

        $innerJson = $this->parseResponse($responseJson, $searchKey);

        if ($innerJson['reviews'] === null) {
            return null;
        }

        foreach ($innerJson['reviews'] as $review) {
            try {
                $commentModel = $this->saveComment($review, $tag);

                if (! $commentModel) {
                    return null;
                }

                $this->sendAndParseFirstAnswerToCommentRequest($review, $commentModel);
            } catch (Exception $e) {
                Log::error($e->getMessage());

                continue;
            }
        }

        usleep(self::DELAY * 1000000);

        return $this->getNextPageUrlParam($responseJson);
    }

    /**
     * @param string|null $urlParam
     * @param string|null $tag
     * @param int|null $page
     *
     * @return string
     */
    public function generateLink(?string $urlParam, string $tag = null, int $page = null): string
    {
        if ($urlParam === null) {
            $urlParam = self::PRODUCT_BASE_URL . $tag . '/?';

            $params = [
                'layout_container' => 'reviewshelfpaginator',
                'layout_page_index' => 3
            ];

            $queryString = http_build_query($params);

            $urlParam .= $queryString;
        } else {
            $pattern = "/page=(\d+)/";
            $urlParam = preg_replace($pattern, "page=$page", $urlParam);
        }

        $urlParam = urlencode($urlParam);

        return self::BASE_URL . $urlParam;
    }

    /**
     * @param array $json
     * @param string $searchSubKey
     *
     * @return string|null
     *
     * @throws OzonParserException
     */
    protected function searchJsonBoxKey(array $json, string $searchSubKey): ?string
    {
        if (! isset($json['widgetStates'])) {
            throw new OzonParserException();
        }

        foreach ($json['widgetStates'] as $key => $widgetState) {
            if (str_contains($key, $searchSubKey)) {
                $searchKey = $key;
                echo $key . PHP_EOL;
            }
        }

        if (! isset($searchKey)) {
            return null;
        }

        return $searchKey;
    }

    /**
     * @param array $json
     * @param string $boxKey
     *
     * @return array
     *
     * @throws OzonParserException
     */
    protected function parseResponse(array $json, string $boxKey): array
    {
        $jsonCommentsBox = json_decode($json['widgetStates'][$boxKey], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OzonParserException();
        }

        return $jsonCommentsBox;
    }

    /**
     * @param array $review
     * @param string $tag
     *
     * @return Review|null
     *
     * @throws OzonParserException
     */
    protected function saveComment(array $review, string $tag): ?Review
    {
        $createdAt = (int)$review['createdAt'] ?? null;
        $lastReviewDate = $this->getOrSetLastReviewDate($tag, $createdAt);

        if ($lastReviewDate - $createdAt > self::ONE_MONTH) {
            return null;
        }
        $createdAt = date('Y-m-d', $createdAt);

        $text = $review['content']['comment'] ?? null;
        $advantages = $review['content']['positive'] ?? null;
        $disadvantages = $review['content']['negative'] ?? null;

        $image = $review['content']['photos'][0]['url'] ?? null;

        return Review::create([
            'text' => $text,
            'published_at' => $createdAt,
            'disadvantages' => $disadvantages,
            'advantages' => $advantages,
            'image' => $image,
            'text_id' => $tag,
        ]);
    }

    /**
     * @param array $review
     * @param Review $reviewModel
     *
     * @return void
     *
     * @throws OzonParserException
     */
    protected function sendAndParseFirstAnswerToCommentRequest(array $review, Review $reviewModel): void
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post(self::ANSWER_TO_COMMENT_URL, [
            'limit' => 10,
            'offset' => 0,
            'reviewUuid' => $review['uuid'],
            'sku' => $review['itemId'],
        ]);

        if ($response->status() !== 200) {
            throw new OzonParserException();
        }

        $responseJson = $response->json();

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OzonParserException();
        }

        $reviewModel->update(['first_response_text' => $responseJson['comments'][0]['comment'] ?? null]);
    }

    /**
     * @param array $json
     *
     * @return string|null
     */
    protected function getNextPageUrlParam(array $json): ?string
    {
        $link = $json['nextPage'] ?? null;

        if ($link === null) {
            return null;
        }

        echo $link . PHP_EOL;

        return $link;
    }

    /**
     * @param string $key
     * @param int $value
     *
     * @return int
     */
    protected function getOrSetLastReviewDate(string $key, int $value): int
    {
        $savedValue = Redis::get($key);

        if ($savedValue !== null ) {
            return $savedValue;
        }

        Redis::set($key, $value);

        return Redis::get($key);
    }
}
