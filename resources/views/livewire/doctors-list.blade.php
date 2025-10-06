<div class="container-fluid mt-4" style="margin-bottom: 80px;" dir="rtl">
    <div class="row">
        <!-- بخش فیلتر -->
        <div class="col-lg-3">
            <div class="filter-box">
                <h5 class="text-primary mb-3">فیلترها</h5>
                <div class="accordion" id="filtersAccordion">

                    <!-- نوع سرویس -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#serviceFilter">
                                نوع سرویس
                            </button>
                        </h2>
                        <div id="serviceFilter" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" wire:model="inPerson" id="inPerson">
                                    <label class="form-check-label" for="inPerson">حضوری</label>
                                </div>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" wire:model="urgent" id="urgent">
                                    <label class="form-check-label" for="urgent">فوری</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" wire:model="phone" id="phone">
                                    <label class="form-check-label" for="phone">تلفنی</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- جنسیت -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#genderFilter">
                                جنسیت
                            </button>
                        </h2>
                        <div id="genderFilter" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" wire:model="male" id="male">
                                    <label class="form-check-label" for="male">مرد</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" wire:model="female" id="female">
                                    <label class="form-check-label" for="female">زن</label>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <!-- بخش لیست پزشکان -->
        <div class="col-lg-9 mb-3">
            <h4 class="mb-3 text-primary">لیست پزشکان</h4>

            <!-- کانتینر با اسکرول -->
            <div class="doctor-list-scroll">
                @forelse($this->filteredDoctors as $doctor)
                    <div class="doctor-card d-flex align-items-center p-3 mb-3">
                        <img src="{{ $doctor['gender'] == 'male' ? asset('assets/img/doc_male.jpg'):asset('assets/img/doc_female.png') }}" alt="دکتر" class="doctor-img me-3">
                        <div class="flex-grow-1">
                            <h5 class="mb-1">{{ $doctor['name'] }}</h5>
                            <p class="text-muted mb-1">{{ $doctor['specialty'] }}</p>
                            <div class="small mb-1">
                                ⭐ {{ $doctor['rating'] }} | خدمات:
                                {{ implode('، ', array_map(fn($s) => $s === 'inPerson' ? 'حضوری' : ($s === 'urgent' ? 'فوری' : 'تلفنی'), $doctor['services'])) }}
                            </div>
                        </div>
                        <button class="btn btn-consult">دریافت مشاوره</button>
                    </div>
                @empty
                    <div class="alert alert-info">هیچ پزشکی با این فیلترها یافت نشد.</div>
                @endforelse
            </div>
        </div>



    </div>
</div>
