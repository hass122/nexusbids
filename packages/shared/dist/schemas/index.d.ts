import { z } from 'zod';
export declare const registerSchema: z.ZodObject<{
    full_name: z.ZodString;
    email: z.ZodString;
    phone: z.ZodOptional<z.ZodString>;
    password: z.ZodString;
    role: z.ZodDefault<z.ZodEnum<["user", "seller"]>>;
}, "strip", z.ZodTypeAny, {
    full_name: string;
    email: string;
    password: string;
    role: "user" | "seller";
    phone?: string | undefined;
}, {
    full_name: string;
    email: string;
    password: string;
    phone?: string | undefined;
    role?: "user" | "seller" | undefined;
}>;
export declare const loginSchema: z.ZodObject<{
    email: z.ZodString;
    password: z.ZodString;
}, "strip", z.ZodTypeAny, {
    email: string;
    password: string;
}, {
    email: string;
    password: string;
}>;
export declare const verifyOtpSchema: z.ZodObject<{
    user_id: z.ZodNumber;
    code: z.ZodString;
}, "strip", z.ZodTypeAny, {
    code: string;
    user_id: number;
}, {
    code: string;
    user_id: number;
}>;
export declare const forgotPasswordSchema: z.ZodObject<{
    email: z.ZodString;
}, "strip", z.ZodTypeAny, {
    email: string;
}, {
    email: string;
}>;
export declare const resetPasswordSchema: z.ZodObject<{
    token: z.ZodString;
    password: z.ZodString;
}, "strip", z.ZodTypeAny, {
    password: string;
    token: string;
}, {
    password: string;
    token: string;
}>;
export declare const placeBidSchema: z.ZodObject<{
    bid_amount: z.ZodNumber;
}, "strip", z.ZodTypeAny, {
    bid_amount: number;
}, {
    bid_amount: number;
}>;
export declare const setAutoBidSchema: z.ZodObject<{
    max_amount: z.ZodNumber;
}, "strip", z.ZodTypeAny, {
    max_amount: number;
}, {
    max_amount: number;
}>;
export declare const createAuctionSchema: z.ZodObject<{
    title: z.ZodString;
    category_id: z.ZodNumber;
    description: z.ZodString;
    starting_price: z.ZodNumber;
    bid_increment: z.ZodDefault<z.ZodNumber>;
    start_time: z.ZodUnion<[z.ZodString, z.ZodString]>;
    end_time: z.ZodUnion<[z.ZodString, z.ZodString]>;
    action: z.ZodDefault<z.ZodEnum<["submit", "draft"]>>;
}, "strip", z.ZodTypeAny, {
    title: string;
    category_id: number;
    description: string;
    starting_price: number;
    bid_increment: number;
    start_time: string;
    end_time: string;
    action: "draft" | "submit";
}, {
    title: string;
    category_id: number;
    description: string;
    starting_price: number;
    start_time: string;
    end_time: string;
    bid_increment?: number | undefined;
    action?: "draft" | "submit" | undefined;
}>;
export declare const updateAuctionSchema: z.ZodObject<{
    title: z.ZodOptional<z.ZodString>;
    category_id: z.ZodOptional<z.ZodNumber>;
    description: z.ZodOptional<z.ZodString>;
    starting_price: z.ZodOptional<z.ZodNumber>;
    bid_increment: z.ZodOptional<z.ZodDefault<z.ZodNumber>>;
    start_time: z.ZodOptional<z.ZodUnion<[z.ZodString, z.ZodString]>>;
    end_time: z.ZodOptional<z.ZodUnion<[z.ZodString, z.ZodString]>>;
    action: z.ZodOptional<z.ZodDefault<z.ZodEnum<["submit", "draft"]>>>;
}, "strip", z.ZodTypeAny, {
    title?: string | undefined;
    category_id?: number | undefined;
    description?: string | undefined;
    starting_price?: number | undefined;
    bid_increment?: number | undefined;
    start_time?: string | undefined;
    end_time?: string | undefined;
    action?: "draft" | "submit" | undefined;
}, {
    title?: string | undefined;
    category_id?: number | undefined;
    description?: string | undefined;
    starting_price?: number | undefined;
    bid_increment?: number | undefined;
    start_time?: string | undefined;
    end_time?: string | undefined;
    action?: "draft" | "submit" | undefined;
}>;
export declare const initiatePaymentSchema: z.ZodObject<{
    order_id: z.ZodNumber;
    payment_method: z.ZodEnum<["jazzcash", "easypaisa", "bank_transfer", "cash_on_delivery"]>;
    sender_number: z.ZodOptional<z.ZodString>;
    sender_name: z.ZodOptional<z.ZodString>;
    notes: z.ZodOptional<z.ZodString>;
}, "strip", z.ZodTypeAny, {
    order_id: number;
    payment_method: "jazzcash" | "easypaisa" | "bank_transfer" | "cash_on_delivery";
    sender_number?: string | undefined;
    sender_name?: string | undefined;
    notes?: string | undefined;
}, {
    order_id: number;
    payment_method: "jazzcash" | "easypaisa" | "bank_transfer" | "cash_on_delivery";
    sender_number?: string | undefined;
    sender_name?: string | undefined;
    notes?: string | undefined;
}>;
export declare const shipOrderSchema: z.ZodObject<{
    courier: z.ZodString;
    tracking_number: z.ZodString;
    tracking_url: z.ZodOptional<z.ZodString>;
    notes: z.ZodOptional<z.ZodString>;
}, "strip", z.ZodTypeAny, {
    courier: string;
    tracking_number: string;
    notes?: string | undefined;
    tracking_url?: string | undefined;
}, {
    courier: string;
    tracking_number: string;
    notes?: string | undefined;
    tracking_url?: string | undefined;
}>;
export declare const reviewSchema: z.ZodEffects<z.ZodObject<{
    order_id: z.ZodNumber;
    rating: z.ZodNumber;
    review_text: z.ZodOptional<z.ZodString>;
    comment: z.ZodOptional<z.ZodString>;
    seller_id: z.ZodOptional<z.ZodNumber>;
    auction_id: z.ZodOptional<z.ZodNumber>;
}, "strip", z.ZodTypeAny, {
    order_id: number;
    rating: number;
    review_text?: string | undefined;
    comment?: string | undefined;
    seller_id?: number | undefined;
    auction_id?: number | undefined;
}, {
    order_id: number;
    rating: number;
    review_text?: string | undefined;
    comment?: string | undefined;
    seller_id?: number | undefined;
    auction_id?: number | undefined;
}>, {
    order_id: number;
    rating: number;
    review_text?: string | undefined;
    comment?: string | undefined;
    seller_id?: number | undefined;
    auction_id?: number | undefined;
}, {
    order_id: number;
    rating: number;
    review_text?: string | undefined;
    comment?: string | undefined;
    seller_id?: number | undefined;
    auction_id?: number | undefined;
}>;
export declare const disputeSchema: z.ZodObject<{
    order_id: z.ZodNumber;
    reason: z.ZodString;
    description: z.ZodString;
    dispute_type: z.ZodDefault<z.ZodEnum<["chargeback", "item_not_received", "item_not_as_described", "unauthorized", "other"]>>;
}, "strip", z.ZodTypeAny, {
    description: string;
    order_id: number;
    reason: string;
    dispute_type: "chargeback" | "item_not_received" | "item_not_as_described" | "unauthorized" | "other";
}, {
    description: string;
    order_id: number;
    reason: string;
    dispute_type?: "chargeback" | "item_not_received" | "item_not_as_described" | "unauthorized" | "other" | undefined;
}>;
export declare const aiChatSchema: z.ZodObject<{
    message: z.ZodString;
    action: z.ZodDefault<z.ZodEnum<["send", "clear", "ping"]>>;
}, "strip", z.ZodTypeAny, {
    message: string;
    action: "send" | "clear" | "ping";
}, {
    message: string;
    action?: "send" | "clear" | "ping" | undefined;
}>;
export declare const aiDescriptionSchema: z.ZodObject<{
    title: z.ZodString;
    category: z.ZodOptional<z.ZodString>;
    condition: z.ZodOptional<z.ZodString>;
    features: z.ZodOptional<z.ZodString>;
}, "strip", z.ZodTypeAny, {
    title: string;
    category?: string | undefined;
    condition?: string | undefined;
    features?: string | undefined;
}, {
    title: string;
    category?: string | undefined;
    condition?: string | undefined;
    features?: string | undefined;
}>;
