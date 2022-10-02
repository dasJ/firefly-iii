<?php
declare(strict_types=1);
/*
 * Controller.php
 * Copyright (c) 2022 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace FireflyIII\Api\V2\Controllers;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidDateException;
use Carbon\Exceptions\InvalidFormatException;
use FireflyIII\Transformers\V2\AbstractTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use League\Fractal\Resource\Collection as FractalCollection;
use League\Fractal\Resource\Item;
use League\Fractal\Serializer\JsonApiSerializer;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Class Controller
 */
class Controller extends BaseController
{
    protected const CONTENT_TYPE = 'application/vnd.api+json';
    protected int          $pageSize;
    protected ParameterBag $parameters;

    /**
     *
     */
    public function __construct()
    {
        $this->parameters = $this->getParameters();
        $this->pageSize   = 50;
        if (auth()->check()) {
            $this->pageSize = (int) app('preferences')->getForUser(auth()->user(), 'listPageSize', 50)->data;
        }
    }

    /**
     * Returns a JSON API object and returns it.
     *
     * @param string              $key
     * @param Model               $object
     * @param AbstractTransformer $transformer
     * @return array
     */
    final protected function jsonApiObject(string $key, Model $object, AbstractTransformer $transformer): array
    {
        // create some objects:
        $manager = new Manager;
        $baseUrl = request()->getSchemeAndHttpHost() . '/api/v2';
        $manager->setSerializer(new JsonApiSerializer($baseUrl));

        $transformer->collectMetaData(new Collection([$object]));

        $resource = new Item($object, $transformer, $key);
        return $manager->createData($resource)->toArray();
    }

    /**
     * @param string               $key
     * @param LengthAwarePaginator $paginator
     * @param AbstractTransformer  $transformer
     * @return array
     */
    final protected function jsonApiList(string $key, LengthAwarePaginator $paginator, AbstractTransformer $transformer): array
    {
        $manager = new Manager;
        $baseUrl = request()->getSchemeAndHttpHost() . '/api/v2';
        $manager->setSerializer(new JsonApiSerializer($baseUrl));

        $objects = $paginator->getCollection();

        // the transformer, at this point, needs to collect information that ALL items in the collection
        // require, like meta data and stuff like that, and save it for later.
        $transformer->collectMetaData($objects);

        $resource = new FractalCollection($objects, $transformer, $key);
        $resource->setPaginator(new IlluminatePaginatorAdapter($paginator));

        return $manager->createData($resource)->toArray();
    }

    /**
     * TODO duplicate from V1 controller
     * Method to grab all parameters from the URL.
     *
     * @return ParameterBag
     */
    private function getParameters(): ParameterBag
    {
        $bag      = new ParameterBag;
        try {
            $page = (int) request()->get('page');
        } catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
            $page = 1;
        }

        $integers = ['limit'];
        $dates    = ['start', 'end', 'date'];

        if ($page < 1) {
            $page = 1;
        }
        if ($page > (2 ^ 16)) {
            $page = (2 ^ 16);
        }
        $bag->set('page', $page);

        // some date fields:
        foreach ($dates as $field) {
            $date = request()->query->get($field);
            $obj  = null;
            if (null !== $date) {
                try {
                    $obj = Carbon::parse($date);
                } catch (InvalidDateException|InvalidFormatException $e) {
                    // don't care
                    Log::warning(sprintf('Ignored invalid date "%s" in API v2 controller parameter check: %s', $date, $e->getMessage()));
                }
            }
            $bag->set($field, $obj);
        }

        // integer fields:
        foreach ($integers as $integer) {
            $value = request()->query->get($integer);
            if (null !== $value) {
                $bag->set($integer, (int) $value);
            }
        }

        // sort fields:
        //   return $this->getSortParameters($bag);

        return $bag;
    }

}