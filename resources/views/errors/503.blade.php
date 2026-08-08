@include('errors.layout', [
    'code' => '503',
    'title' => __('errors.maintenance'),
    'message' => __('errors.maintenance_message'),
])
