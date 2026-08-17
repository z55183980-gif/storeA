INSERT INTO products(id,name,description,price,stock,image_url,sort_order) VALUES
(1,'华为笔记本电脑 MateBook 14 2023','13代酷睿 i5、16G 内存、1T 固态，14英寸轻薄办公本。',4500,19,'/product-images/matebook14.png',1),
(2,'联想笔记本电脑小新16','英特尔酷睿 i5 轻薄本，16英寸屏幕，16G 内存、512G 固态。',2100.12,1,'/product-images/lenovo-xiaoxin16.png',2),
(3,'新秀丽 Samsonite 双肩电脑包','15.6英寸商务背包，适合通勤、旅行及笔记本电脑收纳。',680,35,'/product-images/samsonite-tx5.png',3);
INSERT INTO categories(id,name,description,sort_order) VALUES (1,'数码科技','电脑、办公与智能设备',1),(2,'生活好物','提升日常生活品质的精选好物',2),(3,'办公优选','通勤与办公场景实用装备',3),(4,'潮流配件','兼顾颜值与实用性的时尚配件',4);
UPDATE products SET category_id=CASE id WHEN 1 THEN 1 WHEN 2 THEN 1 WHEN 3 THEN 3 ELSE 2 END;
INSERT INTO settings(name,value) VALUES ('store_name','闪购商城'),('service_hours','09:00-22:00');
