<template>

    <div class="filemanager">
        <draggable v-model="data.models" @end="onSort">
            <div class="filemanager-item filemanager-item-with-name filemanager-item-file filemanager-item-removable"
                v-for="file in data.models"
                :id="'item_'+file.id"
                :key="file.id"
                >
                <div class="filemanager-item-wrapper">
                    <a class="filemanager-item-removable-button" @click="remove(file)" href="#"><span class="fa fa-times"></span></a>
                    <div class="filemanager-item-icon" v-if="file.type === 'i'">
                        <div class="filemanager-item-image-wrapper">
                            <img class="filemanager-item-image" :src="file.thumb_sm" :alt="file.alt_attribute_translated">
                        </div>
                    </div>
                    <div class="filemanager-item-icon" :class="'filemanager-item-icon-'+file.type" v-else></div>
                    <div class="filemanager-item-name">{{ file.name }}</div>
                </div>
            </div>
        </draggable>
    </div>

</template>

<script>
import draggable from 'vuedraggable';

export default {
    components: {
        draggable,
    },
    props: {
        relatedTable: {
            type: String,
            required: true,
        },
        relatedId: {
            type: String,
            required: true,
        },
    },
    data() {
        return {
            data: {
                models: [],
            },
            loading: false,
        };
    },
    created() {
        this.fetchData();
    },
    computed: {
        url() {
            return '/api/' + this.relatedTable + '/' + this.relatedId;
        },
    },
    methods: {
        fetchData() {
            this.loading = true;
            axios
                .get(this.url + '/files')
                .then(response => {
                    this.data = response.data;
                    this.loading = false;
                })
                .catch(error => {
                    alertify.error(
                        error.response.data.message || this.$i18n.t('An error occurred with the data fetch.')
                    );
                });
        },
        remove(file) {
            let index = this.data.models.indexOf(file);

            this.data.models.splice(index, 1);
            this.loading = true;

            axios
                .delete(this.url + '/files/' + file.id, { remove: file.id })
                .then(response => {
                    this.loading = false;
                })
                .catch(error => {
                    this.loading = false;
                    alertify.error('Error ' + error.status + ' ' + error.statusText);
                });
        },
        onSort() {
            axios
                .post('/api/files/sort', this.data.models)
                .then(response => {})
                .catch(error => {
                    alertify.error('Error ' + error.status + ' ' + error.statusText);
                });
        },
    },
};
</script>