<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
//    public function run()
//    {
//        $lists = [
//            [
//                'name' => 'Գրիգոր',
//                'surname' => 'Սահակյան',
//                'tel'   => '+37455655522',
//                'email' => 'admin@gmail.com',
//                'pawnshop_id' => 1,
//                'role' => 'admin',
//                'password' => 'DiamondCredit2024'
//            ],
//            [
//                'name' => 'Կարինե',
//                'surname' => 'Քոլյան',
//                'tel'   => '+37455655522',
//                'email' => 'kolian.karine@gmail.com',
//                'pawnshop_id' => 1,
//                'role' => 'user',
//                'password' => 'KKd_2024'
//            ]
//
//        ];
//        foreach ($lists as $list){
//            $user = new User();
//            $user->name = $list['name'];
//            $user->surname = $list['surname'];
//            $user->tel = $list['tel'];
//            $user->email = $list['email'];
//            $user->pawnshop_id = $list['pawnshop_id'];
//            $user->role = $list['role'];
//            $user->password = bcrypt($list['password']);
//            $user->save();
//        }
//
//
//    }/


    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $users = [
            [
                'name' => 'Գրիգոր',
                'surname' => 'Սահակյան',
                'tel' => '+37455655522',
                'email' => 'admin@gmail.com',
                'pawnshop_id' => 1,
                'role' => 'admin',
                'password' => 'DiamondCredit2024'
            ],
            [
                'name' => 'Կարինե',
                'surname' => 'Քոլյան',
                'tel' => '+37455655522',
                'email' => 'kolian.karine@gmail.com',
                'pawnshop_id' => 1,
                'role' => 'manager',
                'password' => 'KKd_2024'
            ]
        ];

        foreach ($users as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'surname' => $userData['surname'],
                    'tel' => $userData['tel'],
                    'pawnshop_id' => $userData['pawnshop_id'],
                    'role' => $userData['role'],
                    'password' => Hash::make($userData['password']),
                ]
            );

            $user->syncRoles([$userData['role']]);
        }
    }
}
