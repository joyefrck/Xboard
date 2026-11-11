<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\{Facade, Mail};

$GLOBALS['refund_mail_theme']='default';
function admin_setting($key=null,$default=null) { return $key==='email_template' ? $GLOBALS['refund_mail_theme'] : $default; }
$app=new Container(); Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('config', new Illuminate\Config\Repository(['mail'=>[]]));
$app->instance('log',new Psr\Log\NullLogger());
$files=new Filesystem(); $temp=sys_get_temp_dir().'/refund-blade-'.bin2hex(random_bytes(6));mkdir($temp);
$compiler=new Illuminate\View\Compilers\BladeCompiler($files,$temp);
$engines=new Illuminate\View\Engines\EngineResolver();
$engines->register('blade',fn()=>new Illuminate\View\Engines\CompilerEngine($compiler,$files));
$views=new Illuminate\View\Factory($engines,new Illuminate\View\FileViewFinder($files,[dirname(__DIR__).'/resources/views']),new Illuminate\Events\Dispatcher($app));
$views->setContainer($app);$app->instance('view',$views);
$checks=0;
function checkTemplate($value,string $message):void { global $checks;if(!$value)throw new RuntimeException($message);$checks++; }
$values=[
    'name'=>'大象网络','refund_date'=>'2026-11-09 21:20:30','refund_amount'=>'40.00','order_amount'=>'50.00','url'=>'https://example.test/app#/order',
    'orders'=>[['trade_no'=>'202611091234567890','order_date'=>'2026-11-01 08:00:00','order_amount'=>'50.00','balance_payment'=>'0.00','refund_amount'=>'40.00']],
];
try {
    $html=$views->make('mail.default.orderRefund',$values)->render();
    $classic=$views->make('mail.classic.orderRefund',$values)->render();
    $deducted=$values;$deducted['gross_amount']='50.00';$deducted['commission_deduction']='10.00';
    foreach(['default','classic']as $theme){$withCommission=$views->make('mail.'.$theme.'.orderRefund',$deducted)->render();checkTemplate(str_contains($withCommission,'扣佣前折算退款')&&str_contains($withCommission,'−10.00')&&str_contains($withCommission,'+40.00'),'commission deduction and actual receipt shown: '.$theme);}
    checkTemplate(!str_contains($html,'扣除已结算佣金'),'legacy notification payload renders without new fields');
    foreach(['202611091234567890','2026-11-01 08:00:00','50.00 元','+40.00','2026-11-09 21:20:30','站内账户余额','北京时间']as $term){checkTemplate(str_contains($html,$term),'default includes '.$term);checkTemplate(str_contains($classic,$term),'classic includes '.$term);}
    $unsafe=$values;$unsafe['name']='<script>alert(1)</script>';$unsafe['orders'][0]['trade_no']='<img src=x onerror=alert(2)>';
    $escaped=$views->make('mail.default.orderRefund',$unsafe)->render();
    checkTemplate(!str_contains($escaped,'<script>')&&!str_contains($escaped,'<img src=x'),'dynamic fields HTML escaped');
    $merged=$values;$merged['orders'][]=['trade_no'=>'renewal-second','order_date'=>'2026-11-02 09:00:00','order_amount'=>'10.00','balance_payment'=>'5.00','refund_amount'=>'0.00'];
    $multi=$views->make('mail.default.orderRefund',$merged)->render();
    checkTemplate(str_contains($multi,'只入账一次')&&str_contains($multi,'renewal-second')&&str_contains($multi,'另使用余额支付'),'merged notice has totals and balance context');
    // Execute the real MailService template selection with fake transport and log persistence.
    $sent=[];
    $mail=Mockery::mock();$mail->shouldReceive('send')->andReturnUsing(function($view,$data,$callback)use(&$sent,$views){$sent[]=[$view,$views->make($view,$data)->render()];});Mail::swap($mail);
    $log=Mockery::mock('alias:App\Models\MailLog');$log->shouldReceive('create')->andReturn(new stdClass());
    foreach(['default','classic','missing-custom-theme']as $theme){
        $GLOBALS['refund_mail_theme']=$theme;
        $result=App\Services\MailService::sendEmail(['email'=>'receipt@example.test','subject'=>'退款通知','template_name'=>'orderRefund','template_value'=>$values]);
        checkTemplate($result['error']===null,'template sends for '.$theme);
        checkTemplate($result['template_name']===($theme==='classic'?'mail.classic.orderRefund':'mail.default.orderRefund'),'correct template/fallback for '.$theme);
    }
    checkTemplate(count($sent)===3,'fake mail transport captured templates');
    $out=dirname(__DIR__).'/output/playwright/refund-receipt-mail';if(!is_dir($out))mkdir($out,0777,true);
    file_put_contents($out.'/email-preview.html',$html);file_put_contents($out.'/email-merged-preview.html',$multi);
    echo "Refund HTML email: {$checks} checks passed\n";
} finally { foreach(glob($temp.'/*')as $file)unlink($file);rmdir($temp); }
