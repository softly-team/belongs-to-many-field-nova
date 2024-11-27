<template>
  <DefaultField :field="field" :errors="errors" :show-help-text="true">
    <template #field>
      <div
        :style="{ height: field.height ? field.height : 'auto' }"
        class="relative form-control form-input form-control-bordered p-0 w-full"
      >
        <div
          v-if="loading"
          class="py-4 flex justify-center items-center absolute pin z-50 bg-white rounded"
        >
          <Loader class="text-60" />
        </div>
        <div v-if="this.field.selectAll" class="mb-2">
          <input
            type="checkbox"
            id="checkbox"
            class="checkbox"
            v-model="selectAll"
          />
          <label for="checkbox">{{ this.field.messageSelectAll }}</label>
        </div>
        <!--          <label v-if="this.field.selectAll"><input type="checkbox" class="checkbox mb-2 mr-2">{{this.field.messageSelectAll}}</label>-->
        <MultiSelect
          ref="multiselect"
          @open="() => repositionDropdown(true)"
          :options="options"
          v-bind="multiSelectProps"
          v-model="value"
        >
          <template v-slot:noOptions>{{
            field.multiselectSlots.noOptions
          }}</template>
          <template v-slot:noResult>{{
            field.multiselectSlots.noResult
          }}</template>
        </MultiSelect>
        </div>
    </template>
  </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from "laravel-nova";
import MultiSelect from "vue-multiselect";
import isNil from 'lodash/isNil';

export default {
  mixins: [FormField, HandlesValidationErrors],

  props: ["resourceName", "resourceId", "field"],

  components: {
    MultiSelect,
  },
  data() {
    return {
      options: [],
      optionsLabel: "name",
      trackBy: "id",
      dependsOnValue: null,
      isDependant: false,
      shouldClear: false,
      loading: true,
      selectAll: false,
    };
  },
  mounted() {
    window.addEventListener("scroll", this.repositionDropdown);
  },
  destroyed() {
    window.removeEventListener("scroll", this.repositionDropdown);
  },
  created() {
    if (this.field.dependsOn !== undefined) {
      this.isDependant = true;
      this.registerDependencyWatchers(this.$root);
    }
  },

  computed: {
    multiSelectProps() {
      return {
        multiple: true,
        customLabel: (el) => _.get(el, this.optionsLabel),
        trackBy: this.trackBy,
        preselectFirst: false,
        class: this.errorClasses,
        placeholder: this.field.name,
        ...(this.field.multiselectOptions ? this.field.multiselectOptions : {}),
      };
    },

    queryParams() {
      return {
        resourceId: this.resourceId,
        editing: true,
        editMode:
          isNil(this.resourceId) || this.resourceId === ''
            ? 'create'
            : 'update',
      };
    },

  },
  watch: {
    selectAll(value) {
      if (value) {
        this.value = [...this.options];
      } else {
        this.value = [];
      }
    },
  },
  methods: {
    repositionDropdown(onOpen = false) {
      const ms = this.$refs.multiselect;
      if (!ms) return;
      const el = ms.$el;
      const handlePositioning = () => {
        const { top, height, bottom } = el.getBoundingClientRect();
        if (onOpen) ms.$refs.list.scrollTop = 0;
        const fromBottom =
          (window.innerHeight || document.documentElement.clientHeight) -
          bottom;
        ms.$refs.list.style.position = "fixed";
        ms.$refs.list.style.width = `${el.clientWidth}px`;
        if (fromBottom < 300) {
          ms.$refs.list.style.top = "auto";
          ms.$refs.list.style.bottom = `${fromBottom + height}px`;
          ms.$refs.list.style["border-radius"] = "5px 5px 0 0";
        } else {
          ms.$refs.list.style.bottom = "auto";
          ms.$refs.list.style.top = `${top + height}px`;
          ms.$refs.list.style["border-radius"] = "0 0 5px 5px";
        }
      };
      if (onOpen) this.$nextTick(handlePositioning);
      else handlePositioning();
    },
    registerDependencyWatchers(root) {
      root.$children.forEach((component) => {
        if (this.componentIsDependency(component)) {
          if (component.selectedResourceId !== undefined) {
            let attribute = this.findWatchableComponentAttribute(component);
            component.$watch(attribute, this.dependencyWatcher, {
              immediate: true,
            });
            this.dependencyWatcher(component.selectedResourceId);
          }
        }
        this.registerDependencyWatchers(component);
      });
    },

    findWatchableComponentAttribute(component) {
      let attribute;
      if (component.field.component === "belongs-to-field") {
        attribute = "selectedResource";
      } else {
        attribute = "value";
      }
      return attribute;
    },

    componentIsDependency(component) {
      if (component.field === undefined) {
        return false;
      }
      return component.field.attribute === this.field.dependsOn;
    },

    dependencyWatcher(value) {
      if (value === this.dependsOnValue) {
        return;
      }
      this.dependsOnValue = value.value;
      this.fetchOptions();
    },
    /*
     * Set the initial, internal value for the field.
     */
    setInitialValue() {
      this.optionsLabel = this.field.optionsLabel
        ? this.field.optionsLabel
        : "name";
      this.trackBy = this.field.trackBy ? this.field.trackBy : "id";
      this.value = this.field.value.map((el) => ({
        ...el,
        [this.optionsLabel]: _.get(el, this.optionsLabel),
      }));
      this.fetchOptions();
    },

    fetchOptions() {
      if (this.field.options) {
        this.options = this.field.options;
        this.loading = false;
        return;
      }

      let baseUrl = `/nova-vendor/belongs-to-many-field/${this.resourceName}/options/${this.field.attribute}/${this.optionsLabel}`;
      if (this.isDependant) {
        if (this.dependsOnValue) {
          this.loading = true;
          Nova.request().get(
            `${baseUrl}/${this.dependsOnValue}/${this.field.dependsOnKey}`,
            { params: this.queryParams }
          ).then((data) => {
            this.options = data.data;
            this.loading = false;
          });
        } else {
          this.options = [];
          this.loading = false;
        }
      } else {
        Nova.request().get(
          `${baseUrl}`,
          { params: this.queryParams }
        ).then((data) => {
          this.options = data.data;
          this.loading = false;
        });
      }
    },

    /**
     * Fill the given FormData object with the field's internal value.
     */
    fill(formData) {
      formData.append(this.field.attribute, JSON.stringify(this.value) || "");
    },

    /**
     * Update the field's internal value.
     */
    handleChange(value) {
      this.value = value;
      this.$nextTick(() => this.repositionDropdown());
    },
  },
};
</script>

<style src="vue-multiselect/dist/vue-multiselect.css"></style>
