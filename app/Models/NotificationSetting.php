<?php

    namespace App\Models;

    use App\Enums\NotificationChannelTypesEnum;
    use App\Enums\NotificationScopesEnum;
    use App\Enums\WebsiteServicesEnum;
    use App\Mail\EmailReminderSsl;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Mail;
    use Vonage\Client;
    use Vonage\Client\Credentials\Basic;

    class NotificationSetting extends Model
    {
        use HasFactory;

        protected $table = 'notification_settings';

        protected $fillable = [
            'user_id',
            'website_id',
            'scope',
            'inspection',
            'channel_type',
            'address',
            'data_path',
            'flag_active',
        ];

        public function getChannelTypeValueAttribute( $value ): string
        {
            return NotificationChannelTypesEnum::from($this->attributes[ 'channel_type' ])->label();
        }

        public function getScopeValueAttribute( $value ): string
        {
            return NotificationScopesEnum::from($this->attributes[ 'scope' ])->label();
        }

        public function getInspectionValueAttribute( $value ): string
        {
            return WebsiteServicesEnum::from($this->attributes[ 'inspection' ])->label();
        }

        public function website(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(Website::class);
        }

        public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(User::class);
        }

        public function scopeGlobalScope( Builder $query ): void
        {
            $query->where('scope', NotificationScopesEnum::GLOBAL->name);
        }

        public function scopeWebsiteScope( Builder $query ): void
        {
            $query->where('scope', NotificationScopesEnum::WEBSITE->name);
        }

        public function scopeActive( Builder $query ): void
        {
            $query->where('flag_active', 1);
        }

        public function sendSslNotification( ?string $message = null, array $data = [] ): void
        {
            switch ( $this->channel_type ) {
                case 'email':
                    $this->sendEmail($data, EmailReminderSsl::class);
                    break;
                case 'sms':
                    $this->sendSms('SMS with limited text length');
                    break;
                default:
                    Log::error("Unknown channel type: {$this->channel_type}");
            }
        }

        private function sendEmail( $data, $MailClass ): void
        {
            Mail::to($this->address)->send(new $MailClass($data));
        }

        private function sendSms( $message ): void
        {
            $message = $message ?? 'Default SMS message';
            $basic   = new Basic(env('vonage_key'), env('vonage_secret'));
            $client  = new Client($basic);

            $response = $client->message()->send([
                'to'   => $this->phone_number,
                'from' => env('vonage_sms_from'),
                'text' => $message,
            ]);

            if ( $response->getMessages()[ 0 ][ 'status' ] !== '0' ) {
                Log::error('Failed to send SMS: ' . $response->getMessages()[ 0 ][ 'error-text' ]);
            }
        }
    }
