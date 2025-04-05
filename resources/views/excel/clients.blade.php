<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Sheet1</title>
</head>
<body>
<table>
    <thead>
    <tr>
        <th>ID</th>
        <th>Full Name</th>
        <th>Passport</th>
        <th>Date Of Birth</th>
        <th>Phones</th>
        <th>Email</th>
        <th>Address</th>
        <th>Bank Info</th>
        <th>IBAN</th>
        <th>Registered At</th>
    </tr>
    </thead>
    <tbody>
    @foreach($clients as $client)
        <tr>
            <td>{{ $client->id }}</td>
            <td>
                {{
                    implode(' ', array_filter([
                        $client->name,
                        $client->surname,
                        $client->middle_name
                    ]))
                }}
            </td>
            <td>
                {{
                    implode(', ', array_filter([
                        $client->passport_series,
                        $client->passport_validity,
                        $client->passport_issued ? 'տրվ․' . $client->passport_issued : null
                    ]))
                }}
            </td>
            <td>{{ $client->date_of_birth }}</td>
            <td>
                {{
                    implode(', ', array_filter([
                        $client->phone,
                        $client->additional_phone
                    ]))
                }}
            </td>
            <td>{{ $client->email }}</td>
            <td>
                {{
                    implode(', ', array_filter([
                        $client->country,
                        $client->city,
                        $client->street,
                        $client->building
                    ]))
                }}
            </td>
            <td>
                {{
                    implode(', ', array_filter([
                        $client->bank_name,
                        $client->account_number,
                        $client->card_number
                    ]))
                }}
            </td>
            <td>{{ $client->iban }}</td>
            <td>{{ $client->date ? \Carbon\Carbon::parse($client->date)->format('d-m-Y') : '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
