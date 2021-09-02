<?php


namespace Wty\Mongodb;


class OPCode
{
    public const opReply = 1;     /* 对客户端请求的响应. */
    public const opMsg = 1000;    /* 通常的消息命令（跟着字符串） */
    public const opUpdate = 2001; /* 更新document消息 */
    public const opInsert = 2002; /* 插入新document消息*/
    //opGetByOID = 2003,/*保留*/
    public const opQuery = 2004;  /* 查询一个集合*/
    public const opGetMore = 2005; /* 从（一个）查询中获取更多数据，参见 Cursors */
    public const opDelete = 2006; /* 删除一个或多个document*/
    public const opKillCursors = 2007; /* 通知数据库，客户端已执行完毕，可以关闭该Cursors*/
    public const opCOMMAND = 2010;    /*表示命令请求的集群内部协议。已过时，将来会弃用*/
    public const opCOMMANDREPLY = 2011;    /*集群内部协议表示对OP_COMMAND的回复。已过时，将来会弃用*/
    public const opMSG = 2013;    /*使用MongoDB 3.6中引入的格式发送消息。*/
}