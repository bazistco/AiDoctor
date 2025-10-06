<?php

use Illuminate\Support\Facades\Route;

Route::get('/', \App\Livewire\Patient\Dashboard::class)->name('dashboard');
Route::get('/experts', \App\Livewire\DoctorsList::class)->name('experts');
Route::get('/chat', \App\Livewire\Chat::class)->name('chat');
Route::get('/disease/{name}', \App\Livewire\DiseasePage::class)->name('show');
Route::get('/labs', \App\Livewire\LabPacks::class)->name('labs');
Route::get('/test',function (){
    dd(translateExample("A 21-YEAR-LOD PATEN TO YOUR OFFICE with Comments Of Fatigue, Drownsiness, Headache, Nausea, and Recent Weight Loss. The Patient Has a Diagnosis of Leukemia and Reports Had Fever for the Past Three Weeks. Given this information, the first step wool to consider a comment Blood Count (CBC) to Assess the Severity of Anemia, White Blood Cell Count, Platelet Count, and to check if there is any evidence of infection. Additionally, Given the Symptoms Reported by the Patient (Fatigue, Drowsiness, Headache, Nauusea), You May Also Want to Look for Signs of Dehydration Such as Decrened Urine output, Urine, Increased Thirty. A Thorough Physical Examination Should Done to Assess the Patients' Overall Condition. This Wood Include Assessment of Vital Signs, Skin and Mucous Membrane Integrity, Abdomen and Lung Examination. Finally, you may have a number to consider or ordering a complete metabolic panel (CMP) to ashes kidney functions and elecetrilyte Balance as well as elementardiogram (ECG) to Rule ot any card complications. SOME POSSIBLE DIFERENTIAL DIAGNOS FOR THIS PATICIENT INCLUDE: - Infects Such as Sepsis, Meningitis, Or Pneumonia - Leukemia Complications - Medication Side Effects - Other Causes of Fatigue and Drowsiness Such as Depression The Next Steps Wool Depend on the Results of These Investigations and Physical Examination. If the infirmation is confirmed, antibiotics may be prescribed on the Suspected Causative organism. If the Patient Has a Severe Anmia or Thrombocytopenia, Blood Transfusion Might Be Necessary. Please Provide More Information About The CBC Result to Help with Further "));
//     predict_illness('My hands are numb and I feel tingling sensations');
});
Route::get('login', \App\Livewire\Patient\Auth\Login::class)->name('login');
Route::get('predict',\App\Livewire\SymptomPredictor::class)->name('predict');
