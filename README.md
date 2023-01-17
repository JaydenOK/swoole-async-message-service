# Async-Message-Service
## 异步消息服务项目实例
使用多进程启动多个rabbitMQ消费者，常驻监听消费队列数据，协程处理回调推送异步消息。
mysql表配置rabbitmq交换机，队列，路由，回调地址，消费者进程数等信息，可作为独立异步消息服务


#### Quick Start 部署 
```text
1，导入mysql表，增加队列进程配置记录
2，配置config/database.php，config/rabbitmq.php连接信息
3，启动：php service.php start send_code
```

#### 启动、停止队列监听进程
```php
启动：php service.php start send_code
停止：php service.php stop send_code
查看状态：php service.php status send_code
```

#### 进程查看
```shell script
[root@ac_web async-message-service]# ps aux|grep async
root     13542  0.0  0.1 386236 11004 ?        Ss   16:33   0:00 async-master-send_app_msg
root     13543  0.0  0.1 390848 10464 ?        S    16:33   0:00 async-worker-send_app_msg-1
root     13544  0.0  0.1 390848 10460 ?        S    16:33   0:00 async-worker-send_app_msg-2
root     14860  0.0  0.1 388416 11004 ?        Ss   16:41   0:00 async-master-send_code
root     14861  0.0  0.1 466756 11504 ?        S    16:41   0:00 async-worker-send_code-1
root     14862  0.0  0.1 390848 10456 ?        S    16:41   0:00 async-worker-send_code-2
root     14863  0.0  0.1 390848 10456 ?        S    16:41   0:00 async-worker-send_code-3
root     17299  0.0  0.0 112736   972 pts/1    S+   16:55   0:00 grep --color=auto async
```

#### 日志查看
```shell script
[root@ac_web async-message-service]# tail -10 logs/server-2022-11.log 
[root@ac_web async-message-service]# tail -10 logs/callback-2022-11.log
 
[root@ac_web async_message_service]# cat cache/async-master-send_code.pid

```