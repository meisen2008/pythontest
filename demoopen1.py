#!/usr/bin/python
# -*- coding: UTF-8 -*-
 
# 打开一个文件
fo = open("demo1.py", "wb")
print "文件名: ", fo.name
print "是否已关闭 : ", fo.closed
print "访问模式 : ", fo.mode
print "末尾是否强制加空格 : ", fo.softspace