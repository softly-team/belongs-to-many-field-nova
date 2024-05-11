<?php

namespace Benjacho\BelongsToManyField;

use Benjacho\BelongsToManyField\Rules\ArrayRules;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\AssociatableRelation;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\ResourceRelationshipGuesser;
use Laravel\Nova\Contracts\QueryBuilder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\TrashedStatus;


class BelongsToManyField extends Field implements RelatableField
{
    use AssociatableRelation;

    /**
     * The callback to be used for the field's options.
     *
     * @var array|callable
     */
    private $optionsCallback;

    public $showOnIndex = true;
    public $showOnDetail = true;
    public $isAction = false;
    public $selectAll = false;
    public $messageSelectAll = 'Select All';
    public $height = '350px';
    public $viewable = true;
    public $showAsList = false;
    public $pivotData = [];
    private $customRelatableMethod = null;

    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'BelongsToManyField';
    public $relationModel;
    public $label = null;
    public $trackBy = "id";

    /**
     * Create a new field.
     *
     * @param string $name
     * @param string|null $attribute
     * @param string|null $resource
     *
     * @return void
     */
    public function __construct($name, $attribute = null, $resource = null)
    {
        parent::__construct($name, $attribute);
        $resource = $resource ?? ResourceRelationshipGuesser::guessResource($name);

        $this->resource = $resource;

        if ($this->label === null) {
            $this->optionsLabel(($resource)::$title ?? 'name');
        }

        $this->resourceClass = $resource;
        $this->resourceName = $resource::uriKey();
        $this->manyToManyRelationship = $this->attribute;
              
        $this->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($resource) {
            if (is_subclass_of($model, 'Illuminate\Database\Eloquent\Model')) {
                $model::saved(function ($model) use ($attribute, $request) {
                    $inp = json_decode($request->input($attribute), true);

                    \Log::info('attribute:'.$attribute);

                    if ($inp !== null) {
                        $values = array_column($inp, 'id');
                    } else {
                        $values = [];
                    }

                    $query = $model->$attribute();

                    if (!empty($this->pivotData)) {
                        $values = array_fill_keys($values, $this->pivotData);
                    }

                    $query->sync(
                        $values
                    );
                });
                $request->except($attribute);
            }
        });
        $this->localize();
    }

    public function optionsLabel(string $optionsLabel)
    {
        $this->label = $optionsLabel;

        return $this->withMeta(['optionsLabel' => $this->label]);
    }

    public function trackBy(string $trackBy)
    {
        $this->trackBy = $trackBy;
        return $this->withMeta(['trackBy' => $this->trackBy]);
    }

    public function options($options = [])
    {
        $this->optionsCallback = $options;

        return $this;
    }

    public function relationModel($model)
    {
        $this->relationModel = $model;

        return $this;
    }

    public function isAction($isAction = true)
    {
        $this->isAction = $isAction;

        return $this->withMeta(['height' => $this->height]);
    }

    public function canSelectAll($messageSelectAll = 'Select All', $selectAll = true)
    {
        $this->selectAll = $selectAll;
        $this->messageSelectAll = $messageSelectAll;

        return $this->withMeta(['selectAll' => $this->selectAll, 'messageSelectAll' => $this->messageSelectAll]);
    }

    public function showAsListInDetail($showAsList = true)
    {
        $this->showAsList = $showAsList;

        return $this->withMeta(['showAsList' => $this->showAsList]);
    }

    public function viewable($viewable = true)
    {
        $this->viewable = $viewable;

        return $this;
    }

    public function setMultiselectProps($props)
    {
        return $this->withMeta(['multiselectOptions' => $props]);
    }

    public function setMultiselectSlots($slots)
    {
        return $this->withMeta(['multiselectSlots' => $slots]);
    }

    public function dependsOn($dependsOnField, $tableKey)
    {
        return $this->withMeta([
            'dependsOn' => $dependsOnField,
            'dependsOnKey' => $tableKey,
        ]);
    }

    public function rules($rules)
    {
        $rules = ($rules instanceof Rule || is_string($rules)) ? func_get_args() : $rules;
        $this->rules = [new ArrayRules($rules)];

        return $this;
    }

    public function resolve($resource, $attribute = null)
    {
        parent::resolve($resource, $attribute);

        if (!$this->isAction) {
            $this->value = $this->getValue($resource);
        }
    }

    protected function getValue($resource)
    {
        $value = json_decode($resource->{$this->attribute});

        // check if is translatable by checking first item
        // and return value including translated field
        if ($value && $this->value->count()) {
            $optionsLabel = $this->meta['optionsLabel'];
            $translatable = $this->value->first()->translatable;
            if (is_array($translatable) && in_array($optionsLabel, $translatable)) {
                $newValue = [];
                foreach ($value as $item) {
                    $item->{$optionsLabel} = $item->{$optionsLabel}->{app()->getLocale()};
                    $newValue[] = $item;
                }

                return $newValue;
            }
        }

        if ($value) {
            return $value;
        }

        return $this->value;
    }

    /**
     * Get the relationship name.
     *
     * @return string
     */
    public function relationshipName()
    {
        return $this->manyToManyRelationship;
    }

    /**
     * Get the relationship type.
     *
     * @return string
     */
    public function relationshipType()
    {
        return 'belongsToMany';
    }

    public function jsonSerialize() : array
    {
        $this->resolveOptions();

        return array_merge([
            'attribute' => $this->attribute,
            'component' => $this->component(),
            'helpText' => $this->getHelpText(),
            'indexName' => $this->name,
            'name' => $this->name,
            'nullable' => $this->nullable,
            'optionsLabel' => $this->label,
            'trackBy' => $this->trackBy,
            'panel' => $this->panel,
            'prefixComponent' => true,
            'relatable' => true,
            'readonly' => $this->isReadonly(app(NovaRequest::class)),
            'required' => $this->isRequired(app(NovaRequest::class)),
            'resourceNameRelationship' => $this->resourceName,
            'sortable' => $this->sortable,
            'sortableUriKey' => $this->sortableUriKey(),
            'stacked' => $this->stacked,
            'textAlign' => $this->textAlign,
            'value' => $this->value,
            'viewable' => $this->viewable,
            'visible' => $this->visible,
            'validationKey' => $this->validationKey(),
        ], $this->meta());
    }

    public function pivot()
    {
        return $this->pivotData;
    }

    public function setPivot(array $attributes)
    {
        $this->pivotData = $attributes;

        return $this;
    }

    protected function localize()
    {
        $this->setMultiselectProps([
            'selectLabel' => __('belongs-to-many-field-nova::vue-multiselect.select_label'),
            'selectGroupLabel' => __('belongs-to-many-field-nova::vue-multiselect.select_group_label'),
            'selectedLabel' => __('belongs-to-many-field-nova::vue-multiselect.selected_label'),
            'deselectLabel' => __('belongs-to-many-field-nova::vue-multiselect.deselect_label'),
            'deselectGroupLabel' => __('belongs-to-many-field-nova::vue-multiselect.deselect_group_label'),
        ]);

        $this->setMultiselectSlots([
            'noOptions' => $this->getNoOptionsSlot(),
            'noResult' => $this->getNoResultSlot()
        ]);
    }

    protected function getNoOptionsSlot()
    {
        return __('belongs-to-many-field-nova::vue-multiselect.no_options');
    }

    protected function getNoResultSlot()
    {
        return __('belongs-to-many-field-nova::vue-multiselect.no_result');
    }

    private function resolveOptions(): void
    {
        if (isset($this->optionsCallback)) {
            if (is_callable($this->optionsCallback)) {
                $this->withMeta(['options' => call_user_func($this->optionsCallback)]);
            } else {
                $this->withMeta(['options' => collect($this->optionsCallback)]);
            }
        }
    }

    /**
     * Build an attachable query for the field.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @param  bool  $withTrashed
     * @return \Laravel\Nova\Contracts\QueryBuilder
     */
    public function buildAttachableQuery(NovaRequest $request, $withTrashed = false)
    {
        $model = forward_static_call([$resourceClass = $this->resourceClass, 'newModel']);

        $query = app()->make(QueryBuilder::class, [$resourceClass]);

        $request->first === 'true'
                        ? $query->whereKey($model->newQueryWithoutScopes(), $request->current)
                        : $query->search(
                            $request, $model->newQuery(), $request->search,
                            [], [], TrashedStatus::fromBoolean($withTrashed)
                        );

        return $query->tap(function ($query) use ($request, $model) {
            if (is_callable($this->relatableQueryCallback)) {
                call_user_func($this->relatableQueryCallback, $request, $query);

                return;
            }

            forward_static_call($this->attachableQueryCallable($request, $model), $request, $query, $this);
        });
    } 
    
    /**
     * Get the attachable query method name.
     * 
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    protected function attachableQueryCallable(NovaRequest $request, $model)
    {
        return ($method = $this->attachableQueryMethod($request, $model))
                    ? [$request->resource(), $method]
                    : [$this->resourceClass, 'relatableQuery'];
    }

    /**
     * Get the attachable query method name.
     * 
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     */
    protected function attachableQueryMethod(NovaRequest $request, $model)
    {
        $method = 'relatable'.Str::plural(class_basename($model));

        if (method_exists($request->resource(), $method)) {
            return $method;
        }
    }
}
