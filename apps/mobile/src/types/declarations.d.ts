declare module 'react-native' {
  export const StyleSheet: any;
  export const View: any;
  export const Text: any;
  export const TouchableOpacity: any;
  export const ScrollView: any;
  export const TextInput: any;
  export const Modal: any;
  export const ActivityIndicator: any;
  export const Alert: any;
  export const RefreshControl: any;
  export const SafeAreaView: any;
  export const Image: any;
  export const Animated: any;
  export const StatusBar: any;
  export const Dimensions: any;
  export const Platform: any;
  export const FlatList: any;
  export const KeyboardAvoidingView: any;
  export type ViewStyle = any;
  export type TextStyle = any;
  export type ImageStyle = any;
}

declare module 'expo-secure-store' {
  export function setItemAsync(key: string, value: string): Promise<void>;
  export function getItemAsync(key: string): Promise<string | null>;
  export function deleteItemAsync(key: string): Promise<void>;
}
