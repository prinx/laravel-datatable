<?php

namespace Prinx\Laravel\Datatable;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Datatable
{
    protected static $isSearchRequest = null;
    protected $query;
    protected $queryAlreadyBuilt = false;
    protected $originalQuery;
    protected $columns = [];
    protected $data = [];
    protected $renders = [];
    protected $defaultOffset = 0;
    protected $defaultLimit = 10;
    protected $defaultOrderBy = null;
    protected $defaultOrder = 'desc';
    protected $supportedOrders = ['asc', 'desc'];
    protected $defaultSearchValue = null;
    protected $responseData = null;
    protected $tableName = '';
    protected $searchColumns = [];
    protected $rows;

    public static function isSearchRequest()
    {
        if (!is_bool(self::$isSearchRequest)) {
            self::$isSearchRequest = !is_null(request()->input('search.value', null));
        }

        return self::$isSearchRequest;
    }

    /**
     * Create a new data table.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|string $queryOrTable
     *
     * @return Datatable
     */
    public static function from($queryOrTable)
    {
        return new self($queryOrTable);
    }

    /**
     * Create a new data table.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model|\Illuminate\Database\Query\Builder|string $queryOrTable
     *
     * @return void
     */
    public function __construct($queryOrTable)
    {
        $this->retrieveRequestParams();
        $this->retrieveQueryAndColumns($queryOrTable);
    }

    public function retrieveRequestParams()
    {
        $request = request();

        $this->offset = (int) $request->input('start', $this->defaultOffset);
        $this->limit = (int) $request->input('length', $this->defaultLimit);
        $this->searchValue = $request->input('search.value', $this->defaultSearchValue);

        $columnsData = $request->input('columns');
        $this->retrieveColumns($columnsData);

        $orderByColumn = $request->input('order.0.column');
        $this->retrieveOrderBy($columnsData, $orderByColumn);

        $this->retrieveOrder();
    }

    public function retrieveColumns($columnsData)
    {
        $columns = $columnsData;

        if (self::isSearchRequest()) {
            $columns = array_filter($columnsData, function ($column) {
                return $column['searchable'] === 'true';
            });
        }

        $columns = array_map(function ($column) {
            return $column['data'] ?? $column['name'] ?? null;
        }, $columns);

        $columns = array_filter($columns, function ($column) {
            return !is_null($column);
        });

        $this->columns = $columns;
    }

    public function retrieveOrderBy($columnsData, $orderByColumn)
    {
        if (is_numeric($orderByColumn)) {
            $this->orderBy  = $columnsData[$orderByColumn]['data'] ?? $columnsData[$orderByColumn]['name'] ?? $this->defaultOrderBy;
        } elseif (in_array($orderByColumn, $this->columns)) {
            $this->orderBy = $orderByColumn;
        } else {
            $this->orderBy = $this->defaultOrderBy;
        }
    }

    public function retrieveOrder()
    {
        $order = request()->input('order.0.dir', $this->defaultOrder);

        if (!in_array($order, $this->supportedOrders)) {
            $order = $this->defaultOrder;
        }

        $this->order = $order;
    }

    public function retrieveQueryAndColumns($queryOrTable)
    {
        if (is_string($queryOrTable)) { // Either a model class name or a table name was passed
            if ($this->isModelClassName($queryOrTable)) { // Eg: 'App\Models\User' or User::class
                $this->originalQuery = new $queryOrTable;
                $this->tableName = $this->originalQuery->getTable();

                if (empty($this->columns)) {
                    $this->columns = array_keys($this->originalQuery->getAttributes());
                }
            } else { // A table name was passed
                $this->originalQuery = DB::table($queryOrTable);
                $this->tableName = $queryOrTable;

                if (empty($this->columns)) {
                    $this->columns = Schema::getColumnListing($queryOrTable);
                }
            }
        } elseif ($this->isModelInstance($queryOrTable)) {
            $instance = $queryOrTable;
            $modelClass = get_class($instance->getModel());
            $this->originalQuery = new $modelClass;
            $this->tableName = $this->originalQuery->getTable();

            if (empty($this->columns)) {
                $this->columns = array_keys($instance->getAttributes());
                // $this->columns = Schema::getColumnListing($instance->getTable());
            }
        } elseif ($this->isEloquentBuiler($queryOrTable)) {
            $this->originalQuery = $queryOrTable;
            $table = $queryOrTable->getModel()->getTable();
            $this->tableName = $table;

            if (empty($this->columns)) {
                $this->columns = Schema::getColumnListing($table);
            }
        } elseif ($this->isQueryBuiler($queryOrTable)) {
            $this->originalQuery = $queryOrTable;
            $table = $queryOrTable->from;
            $this->tableName = $table;

            if (empty($this->columns)) {
                $this->columns = Schema::getColumnListing($table);
            }
        } else {
            throw new \Exception('Invalid query passed to Datatable');
        }

        $this->query = clone $this->originalQuery;
    }

    public function isModelClassName($name)
    {
        return class_exists($name) && is_subclass_of($name, Model::class);
    }

    public function isModelInstance($model)
    {
        return is_object($model) && is_subclass_of($model, Model::class);
    }

    public function isEloquentBuiler($model)
    {
        return $model instanceof EloquentBuilder;
    }

    public function isQueryBuiler($model)
    {
        return $model instanceof QueryBuilder;
    }

    public function json()
    {
        return $this->response()->json();
    }

    /**
     * Return Laravel's response with the datatable data.
     *
     * @return \Illuminate\Http\Response
     */
    public function response($status = 200)
    {
        if (is_null($this->responseData)) {
            $this->process();
        }

        return response($this->responseData, $status);
    }

    /**
     * Run the datatable query and prepare response data.
     *
     * @return $this
     */
    public function process()
    {
        $this->rows = $this->getQuery()->get();

        if ($this->rows->isNotEmpty()) {
            foreach ($this->rows as $rowData) {
                $rowToDisplay = [];

                foreach ($this->columns as $columnName) {
                    $rowToDisplay[$columnName] = $this->renderCell($rowData, $columnName);
                }

                $this->data[] = $rowToDisplay;
            }
        }

        $this->prepareResponseData();

        return $this;
    }

    /**
     * Returns the query modified by Datatable to retrieve the paginated data from the database.
     *
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public function getQuery()
    {
        if ($this->queryAlreadyBuilt) {
            return $this->query;
        }

        if (self::isSearchRequest()) {
            $this->query->where(function ($query) {
                foreach ($this->columns as $column) {
                    $this->querySearchInColumn($column, $query);
                }
            });
        }

        if (!is_null($this->orderBy)) {
            $this->query->orderBy($this->orderBy, $this->order);
        }

        $this->totalRecords = $this->originalQuery->count();
        $this->recordsFiltered =  self::isSearchRequest() ? $this->query->count() : $this->totalRecords;

        $this->query->skip($this->offset)
            ->limit($this->limit);

        $this->queryAlreadyBuilt = true;

        return $this->query;
    }

    /**
     * Modify the query when the request is a search request.
     *
     * @param string $column
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     *
     * @return void
     */
    public function querySearchInColumn($column, $query)
    {
        if ($this->columnHandledDifferntlyOnSearch($column)) {
            $columnToUse = $this->searchColumns[$column];

            if (is_callable($columnToUse)) {
                $columnToUseName = $columnToUse($query, $this->searchValue, $this);

                if (!is_null($columnToUseName)) {
                    $query->orWhere($columnToUseName, 'like', "%{$this->searchValue}%");
                }
            } elseif(is_string($columnToUse)) {
                $query->orWhere($columnToUse, 'like', "%{$this->searchValue}%");
            }
        } else {
            $query->orWhere("{$this->tableName}.{$column}", 'like', "%{$this->searchValue}%");
        }
    }

    public function columnHandledDifferntlyOnSearch($column)
    {
        return array_key_exists($column, $this->searchColumns);
    }

    public function renderCell($rowData, $columnName)
    {
        if (isset($this->renders[$columnName])) {
            return $this->renders[$columnName]($rowData->$columnName, $rowData, $this);
        }

        return $rowData->$columnName;
    }

    public function prepareResponseData()
    {
        $this->responseData = [
            'draw' => intval(request()->input('draw', 0)),
            'recordsTotal' => $this->totalRecords,
            'recordsFiltered' => $this->recordsFiltered,
            'data' => $this->data,
        ];

        return $this;
    }

    public function getTotalRecords()
    {
        return $this->originalQuery->count();
    }

    public function responseData()
    {
        return $this->responseData;
    }

    /**
     * Add a column definition or modify display value of an existing column.
     *
     * @param string $name The name of the column
     * @param Callable $render The render function - Eg:
     *                  `function ($value, $row, $datatable) {
     *                      return ucfirst($value);
     *                  }`
     *
     * @return $this
     */
    public function column($name, Callable $render)
    {
        if (!in_array($name, $this->columns)) {
            $this->columns[] = $name;
        }

        $this->renders[$name] = $render;

        return $this;
    }

    /**
     * Specify the actual column to use when searching a column.
     *
     * Typically useful when a column is actually a relation and belongs to another table.
     *
     * Eg:
     * `
     * Datatable::from($query)
     *      ->onSearchColumn('column', 'true_column')
     *      ->onSearchColumn('column', function () {
     *          // ...
     *          return 'a_table.column';
     *      })
     *      ->onSearchColumn('column', function ($query, $searchValue, $datatable) {
     *          // ...
     *          $query->having(...);
     *      })
     *      ->response();
     * `
     *
     * @param string $column
     * @param callable|string $columnToUse Can be a string or any function. In the case of a
     *                                     function it will recieve the query as argument. You can
     *                                     modify directly he query or just returned a string
     *                                     hat will be used as column name. In case you modify the
     *                                     query, make sure the function does not return anything.
     *
     * @return $this
     */
    public function onSearchColumn($column, $columnToUse)
    {
        $this->searchColumns[$column] = $columnToUse;

        return $this;
    }

    /**
     * Override a column to use when searching.
     * Alias for `onSearchColumn($column, $columnToUse)`
     */
    public function onSearch($column, $columnToUse)
    {
        return $this->onSearchColumn($column, $columnToUse);
    }

    public function getData()
    {
        return $this->data ?? $this->data = [];
    }

    public function getRows()
    {
        return $this->rows;
    }

    public function getColumns()
    {
        return $this->columns;
    }

    public function setColumns($columns)
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Add column(s)
     *
     * @param string|string[] $column
     * @param integer|null $index
     *
     * @return $this
     */
    public function addColumn($column, $index = null)
    {
        $column = is_array($column) ? $column : [$column];

        if (!in_array($column, $this->columns)) {
            if (is_null($index)) {
                $this->columns = array_merge($this->columns, $column);
            } else {
                $columns = $this->columns;
                array_splice($columns, $index, 0, $column);
                $this->columns = $columns;
            }
        }

        return $this;
    }

    /**
     * Add columns
     *
     * @param string[] $column
     * @param integer|null $index
     *
     * @return $this
     */
    public function addColums($columns, $index)
    {
        return $this->addColumn($columns, $index);
    }

    public function setOriginalQuery($query)
    {
        $this->originalQuery = $query;

        return $this;
    }

    public function getOriginalQuery()
    {
        return $this->originalQuery;
    }

    public function getOffset()
    {
        return $this->offset;
    }

    public function setOffset(int $offset)
    {
        $this->offset = $offset;

        return $this;
    }

    public function getLimit()
    {
        return $this->limit;
    }

    public function setLimit(int $limit)
    {
        $this->limit = $limit;

        return $this;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function setOrder(int $order)
    {
        $this->order = $order;

        return $this;
    }

    public function getOrderBy()
    {
        return $this->orderBy;
    }

    public function setOrderBy(int $orderBy)
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    public function getSearchValue()
    {
        return $this->searchValue;
    }

    public function setSearchValue(string $searchValue)
    {
        $this->searchValue = $searchValue;

        return $this;
    }
}
