<?php

// +----------------------------------------------------------------------
// | CatchAdmin [Just Like ～ ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2021 https://catchadmin.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( https://github.com/JaguarJack/catchadmin-laravel/blob/master/LICENSE.md )
// +----------------------------------------------------------------------
// | Author: JaguarJack [ njphper@gmail.com ]
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace Catch\Traits\DB;

use Catch\Enums\Status;
use Catch\Exceptions\FailedException;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Http\Request as HttpRequest;
/**
 * base operate
 */
trait BaseOperate
{
    use WithEvents, WithRelations;

    /**
     * @return mixed
     */
    public function getList(): mixed
    {
        $fields = property_exists($this, 'fields') ? $this->fields : ['*'];

        $builder = static::select($fields)
            ->creator()
            ->quickSearch();

        // 数据权限
        if ($this->dataRange) {
            $builder = $builder->dataRange();
        }

        // before list
        if ($this->beforeGetList instanceof Closure) {
            $builder = call_user_func($this->beforeGetList, $builder);
        }

        // 排序
        if ($this->sortField && in_array($this->sortField, $this->getFillable())) {
            $builder = $builder->orderBy($this->aliasField($this->sortField), $this->sortDesc ? 'desc' : 'asc');
        }

        // 动态排序
        $dynamicSortField = Request::get('sortField');
        if ($dynamicSortField && $dynamicSortField <> $this->sortField) {
            $builder = $builder->orderBy($this->aliasField($dynamicSortField),  Request::get('order', 'asc'));
        }
        $builder = $builder->orderByDesc($this->aliasField($this->getKeyName()));

        // 分页
        if ($this->isPaginate) {
            return $builder->paginate(Request::get('limit', $this->perPage));
        }

        $data = $builder->get();
        // if set as tree, it will show tree data
        if ($this->asTree) {
            return $data->toTree();
        }

        return $data;
    }

    public function search(HttpRequest $request): mixed
    {
        $fields = property_exists($this, 'fields') ? $this->fields : ['*'];
        list($page, $limit, $where) = $this->buildTableParams($request);
        $builder = static::select($fields)
            ->creator()
            ->quickSearch();

        // 数据权限
        if ($this->dataRange) {
            $builder = $builder->dataRange();
        }
        //查询条件
        $builder = $builder->where($where);

        // before list
        if ($this->beforeGetList instanceof Closure) {
            $builder = call_user_func($this->beforeGetList, $builder);
        }

        // 排序
        if ($this->sortField && in_array($this->sortField, $this->getFillable())) {
            $builder = $builder->orderBy($this->aliasField($this->sortField), $this->sortDesc ? 'desc' : 'asc');
        }

        // 动态排序
        $dynamicSortField = Request::get('sortField');
        if ($dynamicSortField && $dynamicSortField <> $this->sortField) {
            $builder = $builder->orderBy($this->aliasField($dynamicSortField),  Request::get('order', 'asc'));
        }
        $builder = $builder->orderByDesc($this->aliasField($this->getKeyName()));

        // 分页
        if ($this->isPaginate) {
            return $builder->paginate(Request::get('limit', $this->perPage));
        }

        $data = $builder->get();
        // if set as tree, it will show tree data
        if ($this->asTree) {
            return $data->toTree();
        }

        return response()->json($data);
    }

    public function importExcel(HttpRequest $request, array $importTitle = []): mixed
    {
        // 获取上传文件
        $file = $request->file('file');
        if (!$file->isValid()) {
            throw new FailedException('上传文件无效');
        }
        // 解析excel
        // 对接 public static function import($filePath, $startRow = 1, $hasImg = false, $suffix = 'Xlsx', $imageFilePath = null)
        $data = Excel::import($file->getRealPath(), 1, false, 'Xlsx');
        // 返回消息
        // 第一行为Excel表头
        $excel_title = $data[0];
        // 第二行开始为数据
        $excel_data = array_slice($data, 1);
        // 从请求获取data 表字段对应关系
        $meata_data_value = $request->input('data');
        // $meta_data参考格式 {"data":[{"key":"content_title","value":"文章标题"},{"key":"content_details","value":"文章内容"}],"unique":["content_title"]}
        $meta_data = json_decode($meata_data_value, true);
        //[{"key":"content_title","value":"文章标题"},{"key":"content_details","value":"文章内容"}]
        $meta_mapping = $meta_data['data'];
        //["content_title"]
        $unique = $meta_data['unique'];
        // 生成需要保存的数据为array
        DB::beginTransaction();
        try {
            $importCount = 0;
            $updateCount = 0;
            foreach ($excel_data as $row) {
                // 将行数据与标题组合成关联数组
                $rowData = array_combine($excel_title, $row);
                // 构建数据库字段映射
                $mappedData = [];
                foreach ($meta_mapping as $mapping) {
                    $excelHeader = $mapping['value'];
                    $dbField = $mapping['key'];
                    if (isset($rowData[$excelHeader])) {
                        // 这里可以添加数据格式转换逻辑
                        $mappedData[$dbField] = $rowData[$excelHeader];
                    }
                }
                // 构建唯一性查询条件
                $uniqueConditions = [];
                foreach ($unique as $uniqueField) {
                    if (isset($mappedData[$uniqueField])) {
                        $uniqueConditions[$uniqueField] = $mappedData[$uniqueField];
                    }
                }
                // 执行插入/更新操作
                if (!empty($uniqueConditions)) {
                    $model = $this->updateOrCreate(
                        $uniqueConditions,
                        $mappedData
                    );
                    $model->wasRecentlyCreated ? $importCount++ : $updateCount++;
                } else {
                    // 没有唯一约束直接创建
                    $this->save($mappedData);
                    $importCount++;
                }
            }
            DB::commit();
            $message = [
                'message' => '导入成功',
                'stats' => [
                    'created' => $importCount,
                    'updated' => $updateCount
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => '导入失败: ' . $e->getMessage()
            ], 500);
        }
        return response()->json($message);
    }

    public function exportExcel(HttpRequest $request, array $noExportFields = []): mixed
    {
        $fields = property_exists($this, 'fields') ? $this->fields : ['*'];
        list($page, $limit, $where) = $this->buildTableParams($request);
        $builder = static::select($fields)
            ->creator()
            ->quickSearch();

        // 数据权限
        if ($this->dataRange) {
            $builder = $builder->dataRange();
        }
        //查询条件
        $builder = $builder->where($where);

        // before list
        if ($this->beforeGetList instanceof Closure) {
            $builder = call_user_func($this->beforeGetList, $builder);
        }

        // 排序
        if ($this->sortField && in_array($this->sortField, $this->getFillable())) {
            $builder = $builder->orderBy($this->aliasField($this->sortField), $this->sortDesc ? 'desc' : 'asc');
        }

        // 动态排序
        $dynamicSortField = Request::get('sortField');
        if ($dynamicSortField && $dynamicSortField <> $this->sortField) {
            $builder = $builder->orderBy($this->aliasField($dynamicSortField),  Request::get('order', 'asc'));
        }
        $builder = $builder->orderByDesc($this->aliasField($this->getKeyName()));
        $list = $builder->get();
        $tableName = $this->getTable();
        $prefix    = '';
        $dbList    = \Illuminate\Support\Facades\DB::select("show full columns from {$prefix}{$tableName}");
        $header    = [];
        foreach ($dbList as $vo) {
            $comment = !empty($vo->Comment) ? $vo->Comment : $vo->Field;
            if (!in_array($vo->Field, $noExportFields)) {
                $header[] = [$comment, $vo->Field];
            }
        }
        $list     = $list->toArray();
        $fileName = time();
        return Excel::exportData($list, $header, $fileName, 'xlsx');
    }

    /**
     * 驼峰转下划线
     * @param $str
     * @return array|string|null
     */
    public function humpToLine($str): array|string|null
    {
        $str = preg_replace_callback('/([A-Z]{1})/', function ($matches) {
            return '_' . strtolower($matches[0]);
        }, $str);
        return $str;
    }

    /**
     * 构建请求参数
     * @param array $excludeFields 忽略构建搜索的字段
     * @return array
     */
    protected function buildTableParams(HttpRequest $request,array $excludeFields = []): array
    {
        $get     = $request->input();
        $page    = !empty($get['page']) ? $get['page'] : 1;
        $limit   = !empty($get['limit']) ? $get['limit'] : 10;
        $filters = !empty($get['filter']) ? $get['filter'] : [];
        $ops     = !empty($get['op']) ? $get['op'] : [];
        // json转数组
        $where    = [];
        $excludes = [];

        foreach ($filters as $key => $val) {
            if (in_array($key, $excludeFields)) {
                $excludes[$key] = $val;
                continue;
            }
            $op = !empty($ops[$key]) ? $ops[$key] : '%*%';

            switch (strtolower($op)) {
                case '=':
                    $where[] = [$key, '=', $val];
                    break;
                case '%*%':
                    $where[] = [$key, 'LIKE', "%{$val}%"];
                    break;
                case '*%':
                    $where[] = [$key, 'LIKE', "{$val}%"];
                    break;
                case '%*':
                    $where[] = [$key, 'LIKE', "%{$val}"];
                    break;
                case 'in':
                    $where[] = [DB::raw("$key IN ($val)"), 1];
                    break;
                case 'range':
                    [$beginTime, $endTime] = explode(' - ', $val);
                    $where[] = [$key, '>=', strtotime($beginTime)];
                    $where[] = [$key, '<=', strtotime($endTime)];
                    break;
                default:
                    $where[] = [$key, $op, "%{$val}"];
            }
        }
        return [$page, $limit, $where, $excludes];
    }


    /**
     * save
     *
     * @param array $data
     * @return mixed
     */
    public function storeBy(array $data): mixed
    {
        if ($this->fill($this->filterData($data))->save()) {
            if ($this->getKey()) {
                $this->createRelations($data);
            }

            return $this->getKey();
        }

        return false;
    }

    /**
     * create
     *
     * @param array $data
     * @return false|mixed
     */
    public function createBy(array $data): mixed
    {
        $model = $this->newInstance();

        if ($model->fill($this->filterData($data))->save()) {
            return $model->getKey();
        }

        return false;
    }

    /**
     * update
     *
     * @param $id
     * @param array  $data
     * @return mixed
     */
    public function updateBy($id, array $data): mixed
    {
        $model = $this->where($this->getKeyName(), $id)->first();

        $updated = $model->fill($this->filterData($data))->save();

        if ($updated) {
            $this->updateRelations($this->find($id), $data);
        }

        return $updated;
    }

    /**
     * filter data/ remove null && empty string
     *
     * @param array $data
     * @return array
     */
    protected function filterData(array $data): array
    {
        // 表单保存的数据集合
        $fillable = array_unique(array_merge($this->getFillable(), $this->getForm()));

        foreach ($data as $k => $val) {
            if ($this->autoNull2EmptyString && is_null($val)) {
                $data[$k] = '';
            }

            if (! empty($fillable) && ! in_array($k, $fillable)) {
                unset($data[$k]);
            }

            if (in_array($k, [$this->getUpdatedAtColumn(), $this->getCreatedAtColumn()])) {
                unset($data[$k]);
            }
        }

        if ($this->isFillCreatorId && in_array($this->getCreatorIdColumn(), $this->getFillable())) {
            $data['creator_id'] = Auth::guard(getGuardName())->id();
        }

        return $data;
    }


    /**
     * get first by ID
     *
     * @param $value
     * @param null $field
     * @param string[] $columns
     * @return ?Model
     */
    public function firstBy($value, $field = null, array $columns = ['*']): ?Model
    {
        $field = $field ?: $this->getKeyName();

        $model = static::where($field, $value)->first($columns);

        if ($this->afterFirstBy) {
            $model = call_user_func($this->afterFirstBy, $model);
        }

        return $model;
    }

    /**
     * delete model
     *
     * @param $id
     * @param bool $force
     * @return bool|null
     */
    public function deleteBy($id, bool $force = false): ?bool
    {
        /* @var Model $model */
        $model = static::find($id);
        // 如果model有parent_id字段
        if($model->hasAttribute('parent_id') ){
            $parentIdColumn = $this->getParentIdColumn();
            // 如果没有返回有效的parent_id字段名，则跳过相关检查
            if ($parentIdColumn && in_array($parentIdColumn, $this->getFillable())) {
                // 如果模型中存在 parent_id 字段并且有子级记录
                if ($this->where($parentIdColumn, $model->id)->first()) {
                    throw new FailedException('请先删除子级');
                }
            }

        }

        if ($force) {
            $deleted = $model->forceDelete();
        } else {
            $deleted = $model->delete();
        }

        if ($deleted) {
            $this->deleteRelations($model);
        }

        return $deleted;
    }

    /**
     * 批量删除
     *
     * @param array|string $ids
     * @param bool $force
     * @param Closure|null $callback
     * @return true
     */
    public function deletesBy(array|string $ids, bool $force = false, Closure $callback = null): bool
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        DB::transaction(function () use ($ids, $force, $callback){
            foreach ($ids as $id) {
                $this->deleteBy($id, $force);
            }

            if ($callback) {
                $callback($ids);
            }
        });

        return true;
    }

    /**
     * disable or enable
     *
     * @param $id
     * @param string $field
     * @return bool
     */
    public function toggleBy($id, string $field = 'status'): bool
    {
        $model = $this->firstBy($id);

        $status = $model->getAttribute($field) ==  Status::Enable->value() ? Status::Disable->value() : Status::Enable->value();

        $model->setAttribute($field, $status);

        if ($model->save() && in_array($this->getParentIdColumn(), $this->getFillable())) {
            $this->updateChildren($id, $field, $model->getAttribute($field));
        }

        return true;
    }

    /**
     *
     * @param array|string $ids
     * @param string $field
     * @return true
     */
    public function togglesBy(array|string $ids, string $field = 'status'): bool
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        DB::transaction(function () use ($ids, $field){
            foreach ($ids as $id) {
                $this->toggleBy($id, $field);
            }
        });

        return true;
    }


    /**
     * 递归处理
     *
     * @param int|array $parentId
     * @param string $field
     * @param int $value
     */
    public function updateChildren(mixed $parentId, string $field, mixed $value): void
    {
        if (! $parentId instanceof Arrayable) {
            $parentId = Collection::make([$parentId]);
        }

        $childrenId = $this->whereIn($this->getParentIdColumn(), $parentId)->pluck('id');

        if ($childrenId->count()) {
            if ($this->whereIn($this->getParentIdColumn(), $parentId)->update([
                $field => $value
            ])) {
                $this->updateChildren($childrenId, $field, $value);
            }
        }
    }

    /**
     * alias field
     *
     * @param string|array $fields
     * @return string|array
     */
    public function aliasField(string|array $fields): string|array
    {
        $table = $this->getTable();

        if (is_string($fields)) {
            return sprintf('%s.%s', $table, $fields);
        }

        foreach ($fields as &$field) {
            $field = sprintf('%s.%s', $table, $field);
        }

        return $fields;
    }


    /**
     * get updated at column
     *
     * @return string|null
     */
    public function getUpdatedAtColumn(): ?string
    {
        $updatedAtColumn = parent::getUpdatedAtColumn();

        if (! in_array(parent::getUpdatedAtColumn(), $this->getFillable())) {
            $updatedAtColumn = null;
        }

        return $updatedAtColumn;
    }

    /**
     * get created at column
     *
     * @return string|null
     */
    public function getCreatedAtColumn(): ?string
    {
        $createdAtColumn = parent::getCreatedAtColumn();

        if (! in_array(parent::getUpdatedAtColumn(), $this->getFillable())) {
            $createdAtColumn = null;
        }

        return $createdAtColumn;
    }

    /**
     *
     * @return string
     */
    public function getCreatorIdColumn(): string
    {
        return 'creator_id';
    }

    /**
     *
     * @return $this
     */
    protected function setCreatorId(): static
    {
        $this->setAttribute($this->getCreatorIdColumn(), Auth::guard(getGuardName())->id());

        return $this;
    }

    /**
     *
     * @param string $parentId
     * @return $this
     */
    public function setParentIdColumn(string $parentId): static
    {
        $this->parentIdColumn = $parentId;

        return $this;
    }

    /**
     *
     * @param string $sortField
     * @return $this
     */
    protected function setSortField(string $sortField): static
    {
        $this->sortField = $sortField;

        return $this;
    }

    /**
     *
     * @return $this
     */
    protected function setPaginate(bool $isPaginate = true): static
    {
        $this->isPaginate = $isPaginate;

        return $this;
    }

    /**
     * whit form data
     *
     * @return $this
     */
    public function withoutForm(): static
    {
        if (property_exists($this, 'form') && ! empty($this->form)) {
            $this->form = [];
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getForm(): array
    {
        if (property_exists($this, 'form') && ! empty($this->form)) {
            return $this->form;
        }

        return [];
    }

    /**
     * get parent id
     *
     * @return string
     */
    public function getParentIdColumn(): string
    {
        return $this->parentIdColumn;
    }

    /**
     *
     * @return array
     */
    public function getFormRelations(): array
    {
        if (property_exists($this, 'formRelations') && ! empty($this->form)) {
            return $this->formRelations;
        }

        return [];
    }

    /**
     * set data range
     *
     * @param bool $use
     * @return $this
     */
    public function setDataRange(bool $use = true): static
    {
        $this->dataRange = $use;

        return $this;
    }

    /**
     * @param bool $auto
     * @return $this
     */
    public function setAutoNull2EmptyString(bool $auto = true): static
    {
        $this->autoNull2EmptyString = $auto;

        return $this;
    }

    /**
     * @param true $is
     * @return $this
     */
    public function fillCreatorId(bool $is = true): static
    {
        $this->isFillCreatorId = $is;

        return $this;
    }
}
