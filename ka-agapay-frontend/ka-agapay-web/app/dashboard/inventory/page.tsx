// app/(dashboard)/inventory/page.tsx

'use client';

import React, { useEffect, useState } from 'react';
import {
    Table, Button, Tag, Space, Modal, Form,
    Input, InputNumber, Select, message,
    Typography, Card, Alert, Progress, Badge
} from 'antd';
import {
    PlusOutlined, WarningOutlined,
    ArrowUpOutlined, ArrowDownOutlined
} from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import api from '@/lib/api';
import type { InventoryItem } from '@/lib/types';

const { Title, Text } = Typography;

export default function InventoryPage() {
    const [items, setItems] = useState<InventoryItem[]>([]);
    const [alerts, setAlerts] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [stockModal, setStockModal] = useState<{
        visible: boolean;
        item: InventoryItem | null;
        type: 'in' | 'out';
    }>({ visible: false, item: null, type: 'in' });
    const [form] = Form.useForm();

    useEffect(() => {
        fetchInventory();
        fetchAlerts();
    }, []);

    const fetchInventory = async () => {
        try {
            const res = await api.get('/inventory', { params: { rhu_id: 1 } });
            setItems(res.data.data || []);
        } finally {
            setLoading(false);
        }
    };

    const fetchAlerts = async () => {
        const res = await api.get('/inventory/alerts?rhu_id=1');
        setAlerts(res.data);
    };

    const handleStock = async (values: any) => {
        const { item, type } = stockModal;
        if (!item) return;

        try {
            await api.post(`/inventory/${item.id}/stock-${type}`, values);
            message.success(`Stock ${type === 'in' ? 'added' : 'deducted'} successfully.`);
            setStockModal({ visible: false, item: null, type: 'in' });
            form.resetFields();
            fetchInventory();
            fetchAlerts();
        } catch (err: any) {
            message.error(err.response?.data?.message || 'Operation failed');
        }
    };

    const getStockStatus = (item: InventoryItem) => {
        if (item.current_stock === 0) return { color: 'red', text: 'Out of Stock' };
        if (item.current_stock <= item.minimum_stock_level) return { color: 'orange', text: 'Low Stock' };
        return { color: 'green', text: 'OK' };
    };

    const columns: ColumnsType<InventoryItem> = [
        {
            title: 'Item Code',
            dataIndex: 'item_code',
            key: 'item_code',
            render: (val) => (
                <span className="font-mono text-sm text-blue-700">{val}</span>
            ),
        },
        {
            title: 'Name',
            key: 'name',
            render: (_, record) => (
                <div>
                    <div className="font-medium">{record.name}</div>
                    <Tag className="mt-1 text-xs px-2 py-0">
                        {record.category}
                    </Tag>
                </div>
            ),
        },
        {
            title: 'Stock Level',
            key: 'stock',
            render: (_, record) => {
                const pct = Math.min(
                    (record.current_stock / (record.minimum_stock_level * 3)) * 100,
                    100
                );
                const status = getStockStatus(record);
                return (
                    <div style={{ minWidth: 140 }}>
                        <div className="flex justify-between mb-1">
                            <Text strong>{record.current_stock}</Text>
                            <Text className="text-xs text-gray-400">
                                min: {record.minimum_stock_level}
                            </Text>
                        </div>
                        <Progress
                            percent={pct}
                            strokeColor={status.color}
                            showInfo={false}
                            size="small"
                        />
                    </div>
                );
            },
        },
        {
            title: 'Status',
            key: 'status',
            render: (_, record) => {
                const { color, text } = getStockStatus(record);
                return <Badge color={color} text={text} />;
            },
        },
        {
            title: 'Expires',
            dataIndex: 'expiration_date',
            key: 'expiration_date',
            render: (val) => val
                ? <span className="text-sm">{val}</span>
                : <Text className="text-gray-400">—</Text>,
        },
        {
            title: 'Actions',
            key: 'actions',
            render: (_, record) => (
                <Space>
                    <Button
                        size="small"
                        type="primary"
                        icon={<ArrowUpOutlined />}
                        onClick={() => setStockModal({
                            visible: true, item: record, type: 'in'
                        })}
                    >
                        Stock In
                    </Button>
                    <Button
                        size="small"
                        danger
                        icon={<ArrowDownOutlined />}
                        onClick={() => setStockModal({
                            visible: true, item: record, type: 'out'
                        })}
                    >
                        Stock Out
                    </Button>
                </Space>
            ),
        },
    ];

    return (
        <div>
            <div className="flex justify-between items-center mb-6">
                <Title level={3} className="!mb-0">Inventory Management</Title>
                <Button type="primary" icon={<PlusOutlined />}>
                    Add Item
                </Button>
            </div>

            {/* Alerts */}
            {alerts?.low_stock?.length > 0 && (
                <Alert
                    message={`${alerts.low_stock.length} items are running low on stock`}
                    type="warning"
                    showIcon
                    icon={<WarningOutlined />}
                    className="mb-4 rounded-xl"
                />
            )}

            <Card className="rounded-xl shadow-sm">
                <Table
                    columns={columns}
                    dataSource={items}
                    rowKey="id"
                    loading={loading}
                    pagination={{ pageSize: 15 }}
                    rowClassName={(record) =>
                        record.current_stock === 0 ? 'bg-red-50'
                            : record.current_stock <= record.minimum_stock_level ? 'bg-orange-50'
                                : ''
                    }
                />
            </Card>

            {/* Stock In/Out Modal */}
            <Modal
                title={`Stock ${stockModal.type === 'in' ? 'In' : 'Out'} — ${stockModal.item?.name}`}
                open={stockModal.visible}
                onCancel={() => setStockModal({ visible: false, item: null, type: 'in' })}
                onOk={() => form.submit()}
                okText="Confirm"
            >
                <Form form={form} onFinish={handleStock} layout="vertical">
                    <Form.Item
                        name="quantity"
                        label="Quantity"
                        rules={[{ required: true, message: 'Enter quantity' }]}
                    >
                        <InputNumber min={1} className="w-full" />
                    </Form.Item>
                    {stockModal.type === 'out' && (
                        <Form.Item
                            name="reason"
                            label="Reason"
                            rules={[{ required: true, message: 'Enter reason' }]}
                        >
                            <Input.TextArea rows={3} placeholder="Reason for stock out..." />
                        </Form.Item>
                    )}
                    <Form.Item name="notes" label="Notes">
                        <Input.TextArea rows={2} />
                    </Form.Item>
                </Form>
            </Modal>
        </div>
    );
}