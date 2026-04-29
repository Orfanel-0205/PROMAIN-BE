'use client';

import React, { useState } from 'react';
import { Form, Input, Button, Alert, Typography } from 'antd';
import { LockOutlined, PhoneOutlined, MedicineBoxOutlined } from '@ant-design/icons';
import { useRouter } from 'next/navigation';
import Cookies from 'js-cookie';
import api from '@/lib/api';

const { Title, Text } = Typography;

export default function LoginPage() {
    const router = useRouter();
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const onFinish = async (values: { mobile_number: string; password: string }) => {
        setLoading(true);
        setError(null);
        try {
            const res = await api.post('/login', values);
            const token = res.data?.token ?? res.data?.data?.token;
            if (!token) throw new Error('No token returned from server');
            Cookies.set('ka_agapay_token', token, { expires: 7 });
            router.push('/');
        } catch (err: unknown) {
            const axiosError = err as { response?: { data?: { message?: string } } };
            setError(
                axiosError?.response?.data?.message ?? 'Invalid credentials. Please try again.'
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-900 via-blue-800 to-blue-600">
            <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl p-10">
                {/* Logo */}
                <div className="flex flex-col items-center mb-8">
                    <div className="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center mb-4 shadow-lg">
                        <MedicineBoxOutlined className="text-white text-3xl" />
                    </div>
                    <Title level={3} className="!mb-0 !text-blue-900">Ka-agapay</Title>
                    <Text className="text-gray-500 text-sm">RHU Admin Portal — Malasiqui, Pangasinan</Text>
                </div>

                {error && (
                    <Alert
                        message={error}
                        type="error"
                        showIcon
                        closable
                        onClose={() => setError(null)}
                        className="mb-4 rounded-lg"
                    />
                )}

                <Form layout="vertical" onFinish={onFinish} size="large" requiredMark={false}>
                    <Form.Item
                        name="mobile_number"
                        label={<span className="font-medium text-gray-700">Mobile Number</span>}
                        rules={[{ required: true, message: 'Please enter your mobile number' }]}
                    >
                        <Input
                            prefix={<PhoneOutlined className="text-gray-400" />}
                            placeholder="09170000002"
                            className="rounded-lg"
                        />
                    </Form.Item>

                    <Form.Item
                        name="password"
                        label={<span className="font-medium text-gray-700">Password</span>}
                        rules={[{ required: true, message: 'Please enter your password' }]}
                    >
                        <Input.Password
                            prefix={<LockOutlined className="text-gray-400" />}
                            placeholder="••••••••"
                            className="rounded-lg"
                        />
                    </Form.Item>

                    <Form.Item className="mb-0 mt-2">
                        <Button
                            type="primary"
                            htmlType="submit"
                            loading={loading}
                            block
                            className="h-11 rounded-lg bg-blue-600 hover:!bg-blue-700 font-semibold text-base"
                        >
                            Sign In
                        </Button>
                    </Form.Item>
                </Form>

                <Text className="block text-center text-gray-400 text-xs mt-6">
                    Authorized personnel only. Unauthorized access is prohibited.
                </Text>
            </div>
        </div>
    );
}
