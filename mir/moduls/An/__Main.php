<?php

$isCreatorView = $isCreatorView ?? false;

if (empty($tableConfig)) {
    if (empty($error)) {
        if (!empty($html)) {
            echo '<div id="main-page">' . $html . '</div>';
        }
    }
    return;
} ?>
<div id="table"></div>
<script>
    var TableModel = App.models.table(window.location.href, {'updated': <?=($tableConfig['updated'])?><?=($tableConfig['tableRow']['sess_hash'] ?? null) ? ', sess_hash: "' . $tableConfig['tableRow']['sess_hash'] . '"' : ''?>})
</script>
<script>
    let TableConfig = <?=json_encode($tableConfig, JSON_UNESCAPED_UNICODE);?>;

    TableConfig.model = TableModel;
    $(function () {
        new App.pcTableMain($('#table'), TableConfig);
    })
</script>
