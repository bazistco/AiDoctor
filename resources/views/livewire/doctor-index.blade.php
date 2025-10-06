<div class="min-h-screen flex flex-col bg-white">
    <livewire:patient.navbar />
    <livewire:patient.search-bar />
    <livewire:doctors-list :disease="$disease" />
    <livewire:patient.bottom-navigation />
</div>
