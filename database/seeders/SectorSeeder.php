<?php
// LOCATION: database/seeders/SectorSeeder.php

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SectorSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Sports Investment' => [
                'icon' => '⚽',
                'categories' => [
                    'FIFA World Cup',
                    'Player Transfer Market',
                    'Championship Cups',
                    'Domestic Leagues',
                ],
            ],
            'Tech & AI Investment' => [
                'icon' => '💻',
                'categories' => [
                    'AI Software',
                    'Cloud Computing',
                    'Cybersecurity',
                    'Semiconductor Chips',
                    'Solar Energy',
                    'Wind Energy',
                    'Battery Technology',
                    'Electric Vehicles',
                ],
            ],
            'Financial Services' => [
                'icon' => '🏦',
                'categories' => [
                    'Banking',
                    'Insurance',
                    'Payment Processing',
                    'Digital Wallets',
                    'Crypto Infrastructure',
                    'Stocks',
                    'Bonds',
                ],
            ],
            'Real Estate' => [
                'icon' => '🏢',
                'categories' => [
                    'Housing Projects',
                    'Warehouses',
                    'Commercial Properties',
                    'Data Centers',
                ],
            ],
        ];

        $sortOrder = 0;
        foreach ($data as $sectorName => $details) {
            $sector = Sector::firstOrCreate(
                ['slug' => Str::slug($sectorName)],
                [
                    'name'       => $sectorName,
                    'icon'       => $details['icon'],
                    'sort_order' => $sortOrder++,
                    'status'     => 'active',
                ]
            );

            $catSortOrder = 0;
            foreach ($details['categories'] as $categoryName) {
                $sector->categories()->firstOrCreate(
                    ['slug' => Str::slug($categoryName)],
                    [
                        'name'       => $categoryName,
                        'sort_order' => $catSortOrder++,
                        'status'     => 'active',
                    ]
                );
            }
        }
    }
}
