// lib/models/perbaikan_data.dart
import '../services/api_service.dart';

class PerbaikanData {
  final int id;
  final String? departmentName;
  final String? employeeName;
  final String? customerName;
  final String? customerCategoryName;
  final String? pilihanData;
  final String? dataBaru;
  final String? alamatDisplay;
  final String? imageUrl;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  PerbaikanData({
    required this.id,
    this.departmentName,
    this.employeeName,
    this.customerName,
    this.customerCategoryName,
    this.pilihanData,
    this.dataBaru,
    this.alamatDisplay,
    this.imageUrl,
    this.createdAt,
    this.updatedAt,
  });

  factory PerbaikanData.fromJson(Map<String, dynamic> json) {
    int _toInt(dynamic v) => v is int ? v : (int.tryParse('${v ?? 0}') ?? 0);
    DateTime? _toDate(dynamic v) {
      if (v == null) return null;
      final s = v.toString();
      try {
        return DateTime.parse(s);
      } catch (_) {
        return null;
      }
    }

    // ambil alamat manusiawi dari beberapa kandidat
    String alamat = ApiService.formatAddress(
      json['address'] ??
      json['alamat'] ??
      json['alamat_detail'] ??
      json['alamatDisplay'] ??
      json['alamat_display'] ??
      json,
    );

    // kandidat gambar
    final img = (json['image_url'] ??
                json['image'] ??
                json['foto'] ??
                json['gambar'] ??
                '')
            .toString();

    return PerbaikanData(
      id: _toInt(json['id'] ?? json['perbaikan_id'] ?? json['fix_id']),
      departmentName: (json['department']?['name'] ??
              json['department_name'] ??
              json['dept_name'] ??
              json['department'])
          ?.toString(),
      employeeName: (json['employee']?['name'] ??
              json['employee_name'] ??
              json['karyawan'] ??
              json['karyawan_name'])
          ?.toString(),
      customerName: (json['customer']?['name'] ??
              json['customer_name'] ??
              json['nama_customer'])
          ?.toString(),
      customerCategoryName: (json['customer_category']?['name'] ??
              json['customer_category_name'] ??
              json['kategori_customer'])
          ?.toString(),
      pilihanData: (json['pilihan_data'] ?? json['field'] ?? json['pilihan'])?.toString(),
      dataBaru: (json['data_baru'] ?? json['new_value'] ?? json['value'])?.toString(),
      alamatDisplay: alamat,
      imageUrl: ApiService.absoluteUrl(img),
      createdAt: _toDate(json['created_at']),
      updatedAt: _toDate(json['updated_at']),
    );
  }
}
