<?php

namespace App\Services;

class QuizService
{
    /**
     * دریافت فرم تخصصی بر اساس نام تخصص
     */
    public function getFormBySpecialty($specialtyName)
    {

        $forms = $this->getAllForms();

        $form = $forms[$specialtyName] ?? null;

        if (!$form) {
            return [];
        }

        return $form;
    }

    /**
     * تمام فرم‌های تخصصی
     */
    private function getAllForms(): array
    {
        return [
            'قلب و عروق' => [
                'specialty' => 'قلب و عروق',
                'title' => 'فرم تکمیلی قلب و عروق',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر وضعیت قلبی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'chest_pain_duration',
                        'question' => 'درد قفسه سینه چند دقیقه طول می‌کشد؟',
                        'type' => 'select',
                        'options' => ['کمتر از 5 دقیقه', '5 تا 15 دقیقه', '15 تا 30 دقیقه', 'بیشتر از 30 دقیقه'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'pain_radiation',
                        'question' => 'آیا درد به نواحی دیگر (بازو، فک، پشت) منتقل می‌شود؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'heart_rate',
                        'question' => 'ضربان قلب شما در حالت استراحت چند است؟',
                        'type' => 'number',
                        'options' => null,
                        'required' => false,
                        'placeholder' => 'مثال: 75'
                    ],
                    [
                        'id' => 'blood_pressure',
                        'question' => 'آخرین فشار خون شما چقدر بود؟',
                        'type' => 'text',
                        'options' => null,
                        'required' => false,
                        'placeholder' => 'مثال: 120/80'
                    ],
                    [
                        'id' => 'family_history',
                        'question' => 'آیا سابقه بیماری قلبی در خانواده دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر', 'نمی‌دانم'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'smoking',
                        'question' => 'آیا سیگار می‌کشید؟',
                        'type' => 'select',
                        'options' => ['خیر', 'بله - کمتر از 10 نخ در روز', 'بله - 10 تا 20 نخ در روز', 'بله - بیشتر از 20 نخ در روز'],
                        'required' => true,
                        'placeholder' => null
                    ]
                ]
            ],

            'ارتوپدی' => [
                'specialty' => 'ارتوپدی',
                'title' => 'فرم تکمیلی ارتوپدی',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل استخوان و مفاصل خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'pain_location',
                        'question' => 'محل دقیق درد کجاست؟',
                        'type' => 'select',
                        'options' => ['گردن', 'شانه', 'آرنج', 'مچ دست', 'کمر', 'زانو', 'مچ پا', 'سایر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'pain_intensity',
                        'question' => 'شدت درد را از 1 تا 10 مشخص کنید',
                        'type' => 'number',
                        'options' => null,
                        'required' => true,
                        'placeholder' => '1 (خفیف) تا 10 (شدید)'
                    ],
                    [
                        'id' => 'injury_history',
                        'question' => 'آیا اخیراً ضربه یا آسیب دیده‌اید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'movement_limitation',
                        'question' => 'آیا محدودیت حرکتی دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - متوسط', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'swelling',
                        'question' => 'آیا تورم یا قرمزی در ناحیه درد وجود دارد؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'previous_surgery',
                        'question' => 'آیا سابقه جراحی ارتوپدی دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => false,
                        'placeholder' => null
                    ]
                ]
            ],

            'مغز و اعصاب' => [
                'specialty' => 'مغز و اعصاب',
                'title' => 'فرم تکمیلی مغز و اعصاب',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر وضعیت عصبی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'headache_type',
                        'question' => 'نوع سردرد شما چگونه است؟',
                        'type' => 'select',
                        'options' => ['تپشی', 'فشاری', 'سوزشی', 'یک طرفه', 'دو طرفه'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'headache_frequency',
                        'question' => 'چند بار در هفته سردرد دارید؟',
                        'type' => 'select',
                        'options' => ['روزانه', '3-5 بار در هفته', '1-2 بار در هفته', 'کمتر از یک بار در هفته'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'vision_problems',
                        'question' => 'آیا مشکل بینایی یا تاری دید دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'numbness',
                        'question' => 'آیا بی‌حسی یا گزگز در دست یا پا دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'balance_issues',
                        'question' => 'آیا مشکل تعادل یا سرگیجه دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'seizure_history',
                        'question' => 'آیا سابقه تشنج دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ]
                ]
            ],

            'گوارش' => [
                'specialty' => 'گوارش',
                'title' => 'فرم تکمیلی گوارش',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل گوارشی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'pain_location',
                        'question' => 'محل درد شکم کجاست؟',
                        'type' => 'select',
                        'options' => ['بالای شکم', 'وسط شکم', 'پایین شکم', 'سمت راست', 'سمت چپ'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'nausea_vomiting',
                        'question' => 'آیا حالت تهوع یا استفراغ دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'bowel_movement',
                        'question' => 'وضعیت دفع شما چگونه است؟',
                        'type' => 'select',
                        'options' => ['طبیعی', 'یبوست', 'اسهال', 'متناوب'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'blood_in_stool',
                        'question' => 'آیا خون در مدفوع مشاهده کرده‌اید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'weight_loss',
                        'question' => 'آیا اخیراً کاهش وزن داشته‌اید؟',
                        'type' => 'radio',
                        'options' => ['بله - بیش از 5 کیلو', 'بله - کمتر از 5 کیلو', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'appetite',
                        'question' => 'وضعیت اشتهای شما چگونه است؟',
                        'type' => 'select',
                        'options' => ['طبیعی', 'کاهش یافته', 'افزایش یافته', 'بدون اشتها'],
                        'required' => true,
                        'placeholder' => null
                    ]
                ]
            ],

            'پوست و مو' => [
                'specialty' => 'پوست و مو',
                'title' => 'فرم تکمیلی پوست و مو',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل پوستی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'skin_issue_location',
                        'question' => 'محل مشکل پوستی کجاست؟',
                        'type' => 'select',
                        'options' => ['صورت', 'سر و گردن', 'تنه', 'دست‌ها', 'پاها', 'چند نقطه'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'symptom_type',
                        'question' => 'نوع علامت چیست؟',
                        'type' => 'multiselect',
                        'options' => ['قرمزی', 'خارش', 'تورم', 'زخم', 'پوسته‌پوسته شدن', 'جوش', 'لک'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'duration',
                        'question' => 'این مشکل چند وقت است که دارید؟',
                        'type' => 'select',
                        'options' => ['کمتر از یک هفته', '1 تا 4 هفته', '1 تا 3 ماه', 'بیشتر از 3 ماه'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'itching',
                        'question' => 'آیا خارش دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'allergy_history',
                        'question' => 'آیا سابقه حساسیت پوستی دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر', 'نمی‌دانم'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'sun_exposure',
                        'question' => 'آیا اخیراً در معرض آفتاب زیاد بوده‌اید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => false,
                        'placeholder' => null
                    ]
                ]
            ],

            'چشم پزشکی' => [
                'specialty' => 'چشم پزشکی',
                'title' => 'فرم تکمیلی چشم پزشکی',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل چشمی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'vision_problem',
                        'question' => 'مشکل بینایی شما چیست؟',
                        'type' => 'multiselect',
                        'options' => ['تاری دید', 'دوبینی', 'درد چشم', 'قرمزی', 'ترشح', 'حساسیت به نور', 'کاهش دید'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'affected_eye',
                        'question' => 'کدام چشم درگیر است؟',
                        'type' => 'radio',
                        'options' => ['چشم راست', 'چشم چپ', 'هر دو چشم'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'glasses_usage',
                        'question' => 'آیا از عینک یا لنز استفاده می‌کنید؟',
                        'type' => 'radio',
                        'options' => ['بله - عینک', 'بله - لنز', 'بله - هر دو', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'diabetes',
                        'question' => 'آیا دیابت دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'eye_injury',
                        'question' => 'آیا اخیراً ضربه به چشم خورده‌اید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'family_history',
                        'question' => 'آیا سابقه بیماری چشمی در خانواده دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر', 'نمی‌دانم'],
                        'required' => false,
                        'placeholder' => null
                    ]
                ]
            ],

            'گوش و حلق و بینی' => [
                'specialty' => 'گوش و حلق و بینی',
                'title' => 'فرم تکمیلی گوش و حلق و بینی',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'problem_location',
                        'question' => 'مشکل شما در کدام ناحیه است؟',
                        'type' => 'multiselect',
                        'options' => ['گوش', 'حلق', 'بینی', 'سینوس'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'ear_pain',
                        'question' => 'آیا درد گوش دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'hearing_loss',
                        'question' => 'آیا کاهش شنوایی دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'throat_pain',
                        'question' => 'آیا درد گلو دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'nasal_congestion',
                        'question' => 'آیا گرفتگی بینی دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'discharge',
                        'question' => 'آیا ترشح از گوش یا بینی دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ]
                ]
            ],

            'ریه' => [
                'specialty' => 'ریه',
                'title' => 'فرم تکمیلی ریه و تنفس',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل تنفسی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'breathing_difficulty',
                        'question' => 'شدت تنگی نفس شما چقدر است؟',
                        'type' => 'select',
                        'options' => ['خفیف', 'متوسط', 'شدید', 'بدون تنگی نفس'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'cough',
                        'question' => 'آیا سرفه دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - خشک', 'بله - با خلط', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'chest_pain',
                        'question' => 'آیا درد قفسه سینه هنگام تنفس دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'wheezing',
                        'question' => 'آیا صدای خس خس سینه دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'smoking_history',
                        'question' => 'آیا سیگار می‌کشید یا سابقه سیگار دارید؟',
                        'type' => 'select',
                        'options' => ['خیر', 'بله - فعلاً', 'بله - قبلاً', 'سیگار کشیدن غیرفعال'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'fever',
                        'question' => 'آیا تب دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ]
                ]
            ],

            'کلیه و مجاری ادراری' => [
                'specialty' => 'کلیه و مجاری ادراری',
                'title' => 'فرم تکمیلی کلیه و مجاری ادراری',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل کلیوی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'urination_frequency',
                        'question' => 'دفعات ادرار کردن شما چگونه است؟',
                        'type' => 'select',
                        'options' => ['طبیعی', 'زیاد', 'کم', 'شبانه زیاد'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'pain_during_urination',
                        'question' => 'آیا هنگام ادرار کردن درد دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'blood_in_urine',
                        'question' => 'آیا خون در ادرار مشاهده کرده‌اید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'back_pain',
                        'question' => 'آیا درد کمر یا پهلو دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'swelling',
                        'question' => 'آیا تورم در پاها یا صورت دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'kidney_stone_history',
                        'question' => 'آیا سابقه سنگ کلیه دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => false,
                        'placeholder' => null
                    ]
                ]
            ],

            'روانپزشکی' => [
                'specialty' => 'روانپزشکی',
                'title' => 'فرم تکمیلی روانپزشکی',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر وضعیت روانی خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'mood',
                        'question' => 'وضعیت خلقی شما در دو هفته اخیر چگونه بوده؟',
                        'type' => 'select',
                        'options' => ['طبیعی', 'غمگین', 'افسرده', 'بی‌حال', 'پرانرژی بیش از حد'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'sleep_pattern',
                        'question' => 'الگوی خواب شما چگونه است؟',
                        'type' => 'select',
                        'options' => ['طبیعی', 'بی‌خوابی', 'خواب زیاد', 'خواب منقطع'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'appetite_change',
                        'question' => 'آیا تغییر در اشتها داشته‌اید؟',
                        'type' => 'select',
                        'options' => ['افزایش اشتها', 'کاهش اشتها', 'بدون تغییر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'concentration',
                        'question' => 'آیا تمرکز شما کاهش یافته است؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'anxiety',
                        'question' => 'آیا احساس نگرانی یا اضطراب دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'suicidal_thoughts',
                        'question' => 'آیا افکار خودکشی داشته‌اید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ]
                ]
            ],
            'غدد' => [
                'specialty' => 'غدد',
                'title' => 'فرم تکمیلی غدد و متابولیسم',
                'description' => 'لطفاً اطلاعات تکمیلی زیر را برای بررسی دقیق‌تر مشکل غدد درون‌ریز خود پاسخ دهید',
                'questions' => [
                    [
                        'id' => 'weight_change',
                        'question' => 'آیا اخیراً تغییر وزن غیرعادی داشته‌اید؟',
                        'type' => 'select',
                        'options' => ['افزایش وزن ناگهانی', 'کاهش وزن ناگهانی', 'بدون تغییر', 'نوسان وزن'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'fatigue',
                        'question' => 'آیا خستگی و ضعف مفرط دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - شدید', 'بله - متوسط', 'خفیف', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'thirst_urination',
                        'question' => 'آیا تشنگی زیاد یا ادرار مکرر دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - هر دو', 'فقط تشنگی زیاد', 'فقط ادرار مکرر', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'thyroid_symptoms',
                        'question' => 'کدام علائم تیروئیدی را تجربه می‌کنید؟',
                        'type' => 'select',
                        'options' => ['تپش قلب و عصبی بودن', 'سردی مفرط و کندی', 'تورم گردن', 'هیچکدام'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'diabetes_history',
                        'question' => 'آیا سابقه دیابت در خانواده دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - والدین', 'بله - خواهر/برادر', 'بله - سایر بستگان', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'menstrual_issues',
                        'question' => 'آیا اختلال قاعدگی یا مشکلات هورمونی جنسی دارید؟',
                        'type' => 'radio',
                        'options' => ['بله', 'خیر', 'مرد هستم'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'hair_skin_changes',
                        'question' => 'آیا تغییرات پوستی یا ریزش مو دارید؟',
                        'type' => 'radio',
                        'options' => ['بله - ریزش مو', 'بله - خشکی پوست', 'بله - هر دو', 'خیر'],
                        'required' => true,
                        'placeholder' => null
                    ],
                    [
                        'id' => 'blood_sugar_symptoms',
                        'question' => 'آیا علائم کاهش یا افزایش قند خون دارید؟',
                        'type' => 'select',
                        'options' => ['لرزش و گرسنگی ناگهانی', 'خواب‌آلودگی بعد از غذا', 'هر دو', 'هیچکدام'],
                        'required' => true,
                        'placeholder' => null
                    ]
                ]
            ],

        ];
    }
}
