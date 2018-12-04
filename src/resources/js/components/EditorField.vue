<template>
    <div id="app"><ckeditor :editor="editor" v-model="editorData" :config="editorConfig"></ckeditor></div>
</template>

<script>
import CKEditor from '@ckeditor/ckeditor5-vue';
import ClassicEditor from '@ckeditor/ckeditor5-build-classic';
export default {
    name: 'app',
    components: {
        ckeditor: CKEditor.component,
    },
    data() {
        return {
            editor: ClassicEditor,
            editorData: '<p>Coucou Content of the editor.</p>',
            editorConfig: {
                // Configuration of the editor.
            },
        };
    },
};
</script>
